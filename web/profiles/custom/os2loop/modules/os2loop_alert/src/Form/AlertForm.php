<?php

namespace Drupal\os2loop_alert\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\os2loop_alert\Helper\Helper;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Alert form.
 */
class AlertForm extends FormBase {
  use StringTranslationTrait;

  /**
   * The helper.
   *
   * See https://www.drupal.org/project/drupal/issues/3097143#comment-13704423
   * for an explanation of why this prooerty is protected rather than private.
   *
   * @var \Drupal\os2loop_alert\Helper\Helper
   */
  protected $helper;

  /**
   * Constructor.
   */
  public function __construct(Helper $helper) {
    $this->helper = $helper;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('os2loop_alert.helper')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'os2loop_alert_form';
  }

  /**
   * Returns a page title.
   */
  public function getTitle() {
    $node = $this->getNode();

    return $this->t('Send out alert about %title', [
      '%title' => $node->getTitle(),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $isPreview = $form_state->getTemporaryValue('is_preview');
    $node = $this->getNode();
    $subject = $this->helper->getSubject($node);

    $form['subject'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Subject'),
      '#required' => TRUE,
      '#default_value' => $this->t('Important message from [site:name]'),
      '#description' => $this->t('Use <code>[site:name]</code> to insert the site name (required). Other useful tokens: <code>[site:url]</code>'),
    ];

    $form['message'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Message'),
      '#required' => TRUE,
      '#placeholder' => $this->t('Write the message you want to send.'),
      '#description' => $this->t('Use <code>[node:url]</code> to insert the content url (required) and <code>[node:title]</code> to insert the content title. Other useful tokens: <code>[site:name]</code> <code>[site:url]</code>'),
    ];

    $numberOfUsers = $this->helper->getNumberOfUsers();
    $options = [
      'all_users' => $this->formatPlural($numberOfUsers, 'One user', 'All @count users'),
    ];
    $defaultValue = 'all';
    if (NULL !== $subject) {
      $numberOfUsers = $this->helper->getNumberOfSubscribers($subject);
      $options['subject_subscribers'] = $this->formatPlural(
        $numberOfUsers,
        'One subscriber on the subject %subject',
        '@count subscribers on the subject %subject',
        [
          '%subject' => $subject->getName(),
          '@count' => $numberOfUsers,
        ]
      );
      $defaultValue = 'subject_subscribers';
    }
    $form['recipients'] = [
      '#type' => 'radios',
      '#title' => $this->t('Recipients'),
      '#options' => $options,
      '#default_value' => $defaultValue,
    ];

    $form['actions']['#type'] = 'actions';
    $form['actions']['preview'] = [
      '#name' => 'preview',
      '#type' => 'submit',
      '#value' => $this->t('Preview message'),
      '#button_type' => 'secondary',
    ];
    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => Url::fromRoute('entity.node.canonical', [
        'node' => $node->id(),
      ]),
    ];

    if ($isPreview) {
      $form['actions']['send'] = [
        '#name' => 'send',
        '#type' => 'submit',
        '#value' => $this->t('Send alert'),
        '#button_type' => 'primary',
      ];

      $this->buildMessagePreview($form, $form_state);
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $subject = $this->getMessageSubject($form_state);
    if (FALSE === strpos($subject, '[site:name]')) {
      $form_state->setErrorByName('subject', $this->t('Subject does not contain <code>[site:name]</code>'));
    }

    $message = $this->getMessageText($form_state);
    if (FALSE === strpos($message, '[node:url]')) {
      $form_state->setErrorByName('message', $this->t('Message does not contain <code>[node:url]</code>'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {

    $trigger = $form_state->getTriggeringElement();

    switch ($trigger['#name']) {
      case 'send':
        $this->sendAlert($form_state);
        break;

      case 'preview':
      default:
        $form_state
          ->setTemporaryValue('is_preview', TRUE)
          ->setRebuild();
        break;
    }
  }

  /**
   * Send alert.
   */
  private function sendAlert(FormStateInterface $form_state) {
    $subject = $this->getMessageSubject($form_state);
    $message = $this->getMessageText($form_state);
    $recipients = $this->getRecipients($form_state);

    $node = $this->getNode();
    $result = $this->helper->sendAlertMail($subject, $message, $recipients, [
      'node' => $node,
    ]);
    if ($result['result']) {
      $this->messenger()->addMessage($this->t('Your message has been sent.'));
      $form_state->setRedirect('entity.node.canonical', ['node' => $node->id()]);
    }
    else {
      $this->messenger()->addError($this->t('There was a problem sending your message and it was not sent.'));
      $form_state
        ->setRebuild()
        ->set('submitted', TRUE);
    }

    return $result;
  }

  /**
   * Get node from route match.
   */
  private function getNode(): NodeInterface {
    return $this->getRouteMatch()->getParameter('node');
  }

  /**
   * Get subject from form state.
   */
  private function getMessageSubject(FormStateInterface $form_state): string {
    return $this->getText('subject', $form_state);
  }

  /**
   * Get message from form state.
   */
  private function getMessageText(FormStateInterface $form_state): string {
    return $this->getText('message', $form_state);
  }

  /**
   * Get mail adress of all recipients.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return string[]
   *   The recipients.
   */
  private function getRecipients(FormStateInterface $form_state): array {
    $recipients = $form_state->getValue('recipients');
    switch ($recipients) {
      case 'all_users':
        return $this->helper->getUserEmails();

      case 'subject_subscribers':
        $node = $this->getNode();
        $subject = $this->helper->getSubject($node);
        return $this->helper->getAllSubscriberEmails($subject);
    }

    return [];
  }

  /**
   * Get text from form state.
   *
   * @throws \Drupal\Core\Entity\EntityMalformedException
   */
  private function getText(string $name, FormStateInterface $form_state): string {
    return $form_state->getValue($name);
  }

  /**
   * Build message preview.
   */
  private function buildMessagePreview(array &$form, FormStateInterface $form_state) {
    $node = $this->getNode();
    $message = [];
    $this->helper->mail('os2loop_alert', $message, [
      'subject' => $form_state->getValue('subject'),
      'message' => $form_state->getValue('message'),
      'token_data' => [
        'node' => $node,
      ],
    ]);

    $form['preview'] = [
      '#tree' => TRUE,
      '#weight' => 9999,
      '#type' => 'fieldset',
      '#title' => $this->t('Message preview'),

      'from' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#attributes' => [
          'style' => 'white-space: pre',
        ],
        '#value' => $this->t('<strong>From</strong>: @from', ['@from' => $message['from']]),
      ],

      'subject' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#attributes' => [
          'style' => 'white-space: pre',
        ],
        '#value' => $this->t('<strong>Subject</strong>: @subject', ['@subject' => $message['subject']]),
      ],

      'body' => [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#attributes' => [
          'style' => 'white-space: pre',
        ],
        '#value' => $message['body'][0],
      ],
    ];
  }

}
