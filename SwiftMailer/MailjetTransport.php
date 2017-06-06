<?php
namespace Welp\MailjetSwiftMailer\SwiftMailer;

use \Swift_Events_EventDispatcher;
use \Swift_Events_EventListener;
use \Swift_Events_SendEvent;
use \Swift_Mime_Message;
use \Swift_Transport;
use \Swift_Attachment;
use \Swift_MimePart;
use Mailjet\Resources;

/**
 * A SwiftMailer transport implementation for Mailjet
 */
class MailjetTransport implements Swift_Transport
{
    /**
     * @var Swift_Events_EventDispatcher
     */
    protected $eventDispatcher;

    /**
     * @var string|null
     */
    protected $apiKey;

    /**
     * @var string|null
     */
    protected $apiSecret;

    /**
     * @var array|null
     */
    protected $resultApi;

    /**
     * @param Swift_Events_EventDispatcher $eventDispatcher
     */
    public function __construct(Swift_Events_EventDispatcher $eventDispatcher)
    {
        $this->eventDispatcher = $eventDispatcher;
        $this->apiKey = null;
        $this->apiSecret = null;
    }

    /**
     * Not used
     */
    public function isStarted()
    {
        return false;
    }
    /**
     * Not used
     */
    public function start()
    {
    }
    /**
     * Not used
     */
    public function stop()
    {
    }
    /**
     * Not used
     */
    public function ping()
    {
    }

    /**
     * @param string $apiKey
     * @return $this
     */
    public function setApiKey($apiKey)
    {
        $this->apiKey = $apiKey;
        return $this;
    }
    /**
     * @return null|string
     */
    public function getApiKey()
    {
        return $this->apiKey;
    }

    /**
     * @param string $apiSecret
     * @return $this
     */
    public function setApiSecret($apiSecret)
    {
        $this->apiSecret = $apiSecret;
        return $this;
    }
    /**
     * @return null|string
     */
    public function getApiSecret()
    {
        return $this->apiSecret;
    }

    /**
     * @return null|array
     */
    public function getResultApi()
    {
        return $this->resultApi;
    }

    /**
     * @return \Mailjet\Client
     * @throws \Swift_TransportException
     */
    protected function createMailjetClient()
    {
        if ($this->apiKey === null || $this->apiSecret === null) {
            throw new \Swift_TransportException('Cannot create instance of \Mailjet\Client while API key is NULL');
        }
        return new \Mailjet\Client($this->apiKey, $this->apiSecret);
    }

    /**
     * @param Swift_Mime_Message $message
     * @param null $failedRecipients
     * @return int Number of messages sent
     */
    public function send(Swift_Mime_Message $message, &$failedRecipients = null)
    {
        $this->resultApi = null;

        if ($event = $this->eventDispatcher->createSendEvent($this, $message)) {
            $this->eventDispatcher->dispatchEvent($event, 'beforeSendPerformed');
            if ($event->bubbleCancelled()) {
                return 0;
            }
        }

        $sendCount = 0;
        // extract Mailjet Message from SwiftMailer Message
        $mailjetMessage = $this->getMailjetMessage($message);
        // Create mailjetClient
        $mailjetClient = $this->createMailjetClient();
        // send API call
        $this->resultApi = $mailjetClient->post(Resources::$Email, $mailjetMessage);

        // get result
        if ($this->resultApi->success()) {
            $sendCount += $this->resultApi->getCount();
        }

        // Send SwiftMailer Event
        if ($event) {
            if ($sendCount > 0) {
                $event->setResult(Swift_Events_SendEvent::RESULT_SUCCESS);
            } else {
                $event->setResult(Swift_Events_SendEvent::RESULT_FAILED);
            }
            $this->eventDispatcher->dispatchEvent($event, 'sendPerformed');
        }

        return $sendCount;
    }

    /**
     * @param Swift_Events_EventListener $plugin
     */
    public function registerPlugin(Swift_Events_EventListener $plugin)
    {
        $this->eventDispatcher->bindEventListener($plugin);
    }

    /**
     * https://dev.mailjet.com/guides/#send-api-json-properties
     * Convert Swift_Mime_SimpleMessage into Mailjet Payload for send API
     *
     * @param Swift_Mime_Message $message
     * @return array Mailjet Send Message
     * @throws \Swift_SwiftException
     */
    public function getMailjetMessage(Swift_Mime_Message $message)
    {
        $contentType = $this->getMessagePrimaryContentType($message);
        $fromAddresses = $message->getFrom();
        $fromEmails = array_keys($fromAddresses);
        $toAddresses = $message->getTo();
        $ccAddresses = $message->getCc() ? $message->getCc() : [];
        $bccAddresses = $message->getBcc() ? $message->getBcc() : [];

        $attachments = array();
        $headers = array();
        // @TODO retrieve header which is not MJ or Mailjet to add it

        if ($replyTo = $this->getReplyTo($message)) {
            array_push($headers, array('Reply-To' => $replyTo));
        }

        // @TODO only support Recipients so far
        // Format To, Cc, Bcc
        $to = "";
        foreach ($toAddresses as $toEmail => $toName) {
            $to .= "$toName <$toEmail>";
        }
        $cc = "";
        foreach ($ccAddresses as $ccEmail => $ccName) {
            $cc .= "$toName <$toEmail>";
        }
        $bcc = "";
        foreach ($bccAddresses as $bccEmail => $bccName) {
            $bcc .= "$toName <$toEmail>";
        }

        // Handle content
        $bodyHtml = $bodyText = null;
        if ($contentType === 'text/plain') {
            $bodyText = $message->getBody();
        } elseif ($contentType === 'text/html') {
            $bodyHtml = $message->getBody();
        } else {
            $bodyHtml = $message->getBody();
        }


        // Handle attachments
        foreach ($message->getChildren() as $child) {
            if ($child instanceof Swift_Attachment) {
                $attachments[] = array(
                    'Content-type'    => $child->getContentType(),
                    'Filename'    => $child->getFilename(),
                    'content' => base64_encode($child->getBody())
                );
            } elseif ($child instanceof Swift_MimePart && $this->supportsContentType($child->getContentType())) {
                if ($child->getContentType() == "text/html") {
                    $bodyHtml = $child->getBody();
                } elseif ($child->getContentType() == "text/plain") {
                    $bodyText = $child->getBody();
                }
            }
        }

        $mailjetMessage = array(
            'FromEmail'  => $fromEmails[0],
            'FromName'   => $fromAddresses[$fromEmails[0]],
            'Html-part'  => $bodyHtml,
            'Text-part'  => $bodyText,
            'Subject'    => $message->getSubject(),
            'Headers'    => $headers,
            'Recipients' => $this->getRecipients($message)
        );

        if (count($attachments) > 0) {
            $mailjetMessage['Attachments'] = $attachments;
        }

        // Handle custom headers for Mailjet
        if ($message->getHeaders()->has('X-MJ-TemplateID')) {
            $mailjetMessage['Mj-TemplateID'] = $message->getHeaders()->get('X-MJ-TemplateID')->getValue();
        }
        if ($message->getHeaders()->has('X-MJ-TemplateLanguage')) {
            $mailjetMessage['Mj-TemplateLanguage'] = $message->getHeaders()->get('X-MJ-TemplateLanguage')->getValue();
        }
        if ($message->getHeaders()->has('X-MJ-TemplateErrorReporting')) {
            $mailjetMessage['MJ-TemplateErrorReporting'] = $message->getHeaders()->get('X-MJ-TemplateErrorReporting')->getValue();
        }
        if ($message->getHeaders()->has('X-MJ-TemplateErrorDeliver')) {
            $mailjetMessage['MJ-TemplateErrorDeliver'] = $message->getHeaders()->get('X-MJ-TemplateErrorDeliver')->getValue();
        }
        if ($message->getHeaders()->has('X-Mailjet-Prio')) {
            $mailjetMessage['Mj-Prio'] = $message->getHeaders()->get('X-Mailjet-Prio')->getValue();
        }
        if ($message->getHeaders()->has('X-Mailjet-Campaign')) {
            $mailjetMessage['Mj-campaign'] = $message->getHeaders()->get('X-Mailjet-Campaign')->getValue();
        }
        if ($message->getHeaders()->has('X-Mailjet-DeduplicateCampaign')) {
            $mailjetMessage['Mj-deduplicatecampaign'] = $message->getHeaders()->get('X-Mailjet-DeduplicateCampaign')->getValue();
        }
        if ($message->getHeaders()->has('X-Mailjet-TrackOpen')) {
            $mailjetMessage['Mj-trackopen'] = $message->getHeaders()->get('X-Mailjet-TrackOpen')->getValue();
        }
        if ($message->getHeaders()->has('X-Mailjet-TrackClick')) {
            $mailjetMessage['Mj-trackclick'] = $message->getHeaders()->get('X-Mailjet-TrackClick')->getValue();
        }
        if ($message->getHeaders()->has('X-MJ-CustomID')) {
            $mailjetMessage['Mj-CustomID'] = $message->getHeaders()->get('X-MJ-CustomID')->getValue();
        }
        if ($message->getHeaders()->has('X-MJ-EventPayLoad')) {
            $mailjetMessage['Mj-EventPayLoad'] = $message->getHeaders()->get('X-MJ-EventPayLoad')->getValue();
        }
        if ($message->getHeaders()->has('X-MJ-Vars')) {
            $mailjetMessage['Vars'] = $message->getHeaders()->get('X-MJ-Vars')->getValue();
        }

        // @TODO bulk messages

        return $mailjetMessage;
    }

    /**
     * Get all the addresses this message should be sent to.
     *
     * @param Swift_Mime_Message $message
     *
     * @return array
     */
    protected function getRecipients(Swift_Mime_Message $message)
    {
        $to = [];
        if ($message->getTo()) {
            $to = array_merge($to, $message->getTo());
        }
        if ($message->getCc()) {
            $to = array_merge($to, $message->getCc());
        }
        if ($message->getBcc()) {
            $to = array_merge($to, $message->getBcc());
        }
        $recipients = [];
        foreach ($to as $address => $name) {
            $recipients[] = ['Email' => $address, 'Name' => $name];
        }
        return $recipients;
    }

    /**
     * Get the 'reply_to' headers and format as required by Mailjet.
     *
     * @param Swift_Mime_Message $message
     *
     * @return string|null
     */
    protected function getReplyTo(Swift_Mime_Message $message)
    {
        if (is_array($message->getReplyTo())) {
            return current($message->getReplyTo()).' <'.key($message->getReplyTo()).'>';
        }
    }

    /**
     * @return array
     */
    protected function getSupportedContentTypes()
    {
        return array(
            'text/plain',
            'text/html'
        );
    }

    /**
     * @param string $contentType
     * @return bool
     */
    protected function supportsContentType($contentType)
    {
        return in_array($contentType, $this->getSupportedContentTypes());
    }

    /**
     * @param Swift_Mime_Message $message
     * @return string
     */
    protected function getMessagePrimaryContentType(Swift_Mime_Message $message)
    {
        $contentType = $message->getContentType();
        if ($this->supportsContentType($contentType)) {
            return $contentType;
        }
        // SwiftMailer hides the content type set in the constructor of Swift_Mime_SimpleMessage as soon
        // as you add another part to the message. We need to access the protected property
        // userContentType to get the original type.
        $messageRef = new \ReflectionClass($message);
        if ($messageRef->hasProperty('userContentType')) {
            $propRef = $messageRef->getProperty('userContentType');
            $propRef->setAccessible(true);
            $contentType = $propRef->getValue($message);
        }
        return $contentType;
    }
}
