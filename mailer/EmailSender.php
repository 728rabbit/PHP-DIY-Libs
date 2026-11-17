<?php
/*
EmailSender usage guide:

$config['email_agent'] = 'default';
$config['sender_email'] = 'no-reply@domain.com';
$config['email_sender_name'] = '';

$config['sendgrid_key'] = '';

$config['smtp_host'] = 'smtp.gmail.com';
$config['smtp_port'] = '587';
$config['smtp_username'] = '';
$config['smtp_password'] = '';
$config['smtp_secure'] = 'tls';

--------------------------------------------------------------------------------
1. Init:

$myEmailSender = new \App\Libs\mailer\EmailSender();

OR

$myEmailSender = new \App\Libs\mailer\EmailSender('smtp.example.com', 587, 'your_email@example.com', 'your_password', 'tls');

--------------------------------------------------------------------------------
2. Sender:

$myEmailSender->setSender('demo@example.com');

OR

$myEmailSender->setSender('demo@example.com', 'demo');

--------------------------------------------------------------------------------
3. Recipients:

$myEmailSender->setRecipients('test@example.com');

OR

$myEmailSender->setRecipients('test@example.com;test_2@example.com');

OR

$myEmailSender->setRecipients(['test_1@example.com', 'test_2@example.com']);

--------------------------------------------------------------------------------
4. Send:

$myEmailSender->sendOut('Here is the subject', 'This is the HTML message body <b>in bold!</b>');

OR

$myEmailSender->sendOut('Here is the subject', 'This is the HTML message body <b>in bold!</b>', '/path/to/file1.zip');

OR

$myEmailSender->sendOut('Here is the subject', 'This is the HTML message body <b>in bold!</b>', ['/path/to/file1.zip', '/path/to/file2.zip']);

*/
namespace App\Libs\mailer;

/**
 * Email sender.
 *
 * Sends HTML email with optional attachments through either:
 *  - SMTP   (via PHPMailer; the default when the agent is not "sendgrid")
 *  - SendGrid (via the v3 mail/send REST API; requires an API key)
 *
 * Local images referenced in the HTML body are inlined as CID attachments
 * so they render in the recipient's client. Recipients and attachments are
 * validated and de-duplicated before sending.
 */
class EmailSender {
    /** Validated recipient email addresses. */
    private $recipients = [];
    
    /** Sender email address and display name. */
    private $senderEmail = '';
    private $senderName = ''; 
    
    /** SMTP connection settings. */
    private $smtpServer = '';
    private $smtpPort = '';
    private $smtpUser = '';
    private $smtpPassword = '';
    private $smtpSecure = 'ssl';
    
    /** Delivery agent ("smtp", "sendgrid", or "" for default). */
    private $agent = '';
    /** SendGrid API key (used when the agent is "sendgrid"). */
    private $agentKey = '';

    /**
     * Constructor.
     *
     * SMTP settings may be supplied here or later via setSMTP().
     *
     * @param string $smtpServer
     * @param string $smtpPort
     * @param string $smtpUser
     * @param string $smtpPassword
     * @param string $smtpSecure "ssl" (default) or "tls"
     */
    public function __construct($smtpServer = '', $smtpPort = '', $smtpUser = '', $smtpPassword = '', $smtpSecure = 'ssl') {
        $this->smtpServer = $smtpServer;
        $this->smtpPort = $smtpPort;
        $this->smtpUser = $smtpUser;
        $this->smtpPassword = $smtpPassword;
        $this->smtpSecure = $smtpSecure;
    }

    /**
     * Set the SMTP connection settings.
     *
     * @param string $smtpServer
     * @param string $smtpPort
     * @param string $smtpUser
     * @param string $smtpPassword
     * @param string $smtpSecure "ssl" (default) or "tls"
     * @return static
     */
    public function setSMTP($smtpServer, $smtpPort, $smtpUser, $smtpPassword, $smtpSecure = 'ssl') {
        $this->smtpServer = $smtpServer;
        $this->smtpPort = $smtpPort;
        $this->smtpUser = $smtpUser;
        $this->smtpPassword = $smtpPassword;
        $this->smtpSecure = $smtpSecure;
        return $this;
    }
    
    /**
     * Set the delivery agent and its API key.
     *
     * @param string $name "smtp" or "sendgrid"
     * @param string $key  SendGrid API key (only needed for "sendgrid")
     * @return void
     */
    public function setAgent($name = '', $key = '') {
        $this->agent = strtolower(trim($name));
        $this->agentKey = $key;
    }

    /**
     * Set the sender address (and optional display name).
     *
     * @param string $email
     * @param string $name
     * @return static
     */
    public function setSender($email, $name = '') {
        $this->senderEmail = $email;
        $this->senderName = $name;
        return $this;
    }
    
    /**
     * Set and validate the recipients.
     *
     * Accepts an array, a semicolon-separated string, or a newline-separated
     * string. Entries are trimmed, de-duplicated, and filtered to valid
     * email addresses.
     *
     * @param string|array $toEmail
     * @return static
     */
    public function setRecipients($toEmail = '') {
        if(!empty($toEmail)){
            // Normalize to an array of candidate addresses
            if(is_array($toEmail)) {
                $this->recipients = array_filter(array_unique($toEmail));
            }
            else {
                $toEmail = explode(PHP_EOL, str_replace(';', PHP_EOL, $toEmail));
                $this->recipients = array_filter(array_unique($toEmail));
            }
            // Keep only valid, trimmed email addresses
            if(!empty($this->recipients)) {
                foreach ($this->recipients as $recipientKey => $recipient) {
                    $recipient = trim($recipient);
                    if (filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
                        $this->recipients[$recipientKey] = $recipient;
                    }
                    else {
                        unset($this->recipients[$recipientKey]);
                    }
                }
            }
        }
        return $this;
    }

    /**
     * Send the email through the selected agent.
     *
     * @param string       $subject
     * @param string       $content    HTML body
     * @param string|array $attachment File path(s) to attach
     * @param bool         $debug
     * @return bool
     */
    public function sendOut($subject, $content, $attachment = [], $debug = false) {
        if($this->agent == 'sendgrid') {
            return $this->ViaSendGrid($subject, $content, $attachment, $debug);
        }
        else {
            return $this->ViaSMTP($subject, $content, $attachment, $debug);
        }
    }
    
    /**
     * Send the email via SMTP (PHPMailer).
     *
     * When the agent is "smtp" and full SMTP credentials are configured,
     * an authenticated SMTP connection is used; otherwise PHPMailer's
     * default mail transport is used. Local images in the body are embedded
     * as CID attachments.
     *
     * @param string       $subject
     * @param string       $content
     * @param string|array $attachment
     * @param bool         $debug
     * @return bool
     */
    private function ViaSMTP($subject, $content, $attachment = [], $debug = false) {
        try {
            if(!empty($this->recipients) && !empty($subject) && !empty($content)) {
                // Load the PHPMailer core classes
                require_once('Exception.php');
                require_once('PHPMailer.php');
                if($this->agent == 'smtp') {
                    if (!empty($this->smtpServer) && !empty($this->smtpPort) && !empty($this->smtpUser) && !empty($this->smtpPassword)) {
                        require_once('SMTP.php');
                    }
                }
                
                $mail = new \PHPMailer\PHPMailer\PHPMailer(); 
                $mail->CharSet = 'UTF-8';

                // Enable authenticated SMTP when fully configured
                if($this->agent == 'smtp') {
                    if (!empty($this->smtpServer) && !empty($this->smtpPort) && !empty($this->smtpUser) && !empty($this->smtpPassword)) {
                        $mail->IsSMTP(); 
                        $mail->Mailer       =   'smtp';
                        $mail->SMTPDebug    =   $debug;  
                        $mail->SMTPAuth     =   TRUE;
                        $mail->Host         =   $this->smtpServer;
                        $mail->Port         =   $this->smtpPort;
                        $mail->Username     =   $this->smtpUser;
                        $mail->Password     =   $this->smtpPassword;
                        $mail->SMTPSecure   =   strtoupper($this->smtpSecure);
                    }
                }

                $mail->IsHTML(true);
                $mail->Subject = $subject;
                
                // Embed local images as CID attachments and rewrite their src
                preg_match_all('/<img[^>]+src="((?!https|http)[^">]+)"/i', $content, $allMatches);
                if(!empty($allMatches) && !empty($allMatches[1])) {
                    $imagePaths = $allMatches[1];
                    // Replace each local src with a cid: reference
                    foreach ($imagePaths as $index => $imagePath) {
                        $cid = 'image' . $index;
                        $content = str_replace($imagePath, ('cid:'.$cid), $content);
                        $mail->addEmbeddedImage($imagePath, $cid);
                    }
                }
                $mail->MsgHTML(('<div>'.$content.'</div>')); 
                
                // Attach any existing files
                if(!empty($attachment)) {
                    if(is_string($attachment)) {
                        $attachment = [$attachment];
                    }
                    foreach ($attachment as $file) {
                        if(file_exists($file)) {
                            $mail->addAttachment($file);
                        }
                    }
                }

                // Add all validated recipients and the sender
                foreach ($this->recipients as $recipient) {
                    $mail->AddAddress(trim($recipient));
                }
                $mail->SetFrom($this->senderEmail, $this->senderName);
                return $mail->Send();
            }
        } catch (Exception $ex) {
            throw new Exception('Mail sending failed: ' . $ex->getMessage());
        }
        
        return false;
    }
    
    /**
     * Send the email via the SendGrid v3 API.
     *
     * Builds the SendGrid message payload, converting local images in the
     * body to inline CID attachments and adding any standard attachments.
     * Requires a valid SendGrid API key (set via setAgent()).
     *
     * @param string       $subject
     * @param string       $content
     * @param string|array $attachment
     * @param bool         $debug
     * @return bool
     */
    private function ViaSendGrid($subject, $content, $attachment = [], $debug = false) {
        try {
            if(!empty($this->recipients) && !empty($subject) && !empty($content)) {
                // Build the recipient list in SendGrid's expected shape
                $toEmailList = [];
                foreach ($this->recipients as $recipient) {
                    $toEmailList[] = ['email' => $recipient];
                }
                
                // Collect embedded images (local file paths only)
                $embeddedImages = [];

                // Match image paths in the HTML body
                preg_match_all('/<img[^>]+src=["\'](.*?)["\']/i', $content, $matches);
                foreach ($matches[1] as $index => $path) {
                    // Only inline images that exist on disk
                    if (file_exists($path)) {
                        // Generate a unique CID and rewrite the src
                        $cid = 'image' . $index;
                        $content = str_replace($path, 'cid:' . $cid, $content);
                        $embeddedImages[$cid] = $path;
                    }
                }

                // Build the base SendGrid message payload
                $emailData = 
                [
                    'personalizations'  => 
                    [
                        [
                            'to'        =>  $toEmailList,
                            'subject'   =>  $subject
                        ]
                    ],
                    'from' =>  
                    [
                        'email'         =>  $this->senderEmail,
                        'name'          =>  $this->senderName
                    ],
                    'content'           =>  
                    [
                        [
                            'type'      =>  'text/html',
                            'value'     =>  $content
                        ]
                    ]
                ];
                
                // Add embedded images as inline attachments
                foreach ($embeddedImages as $cid => $file) {
                    $emailData['attachments'][] = [
                        'content' => base64_encode(file_get_contents($file)),
                        'type' => mime_content_type($file),
                        'filename' => basename($file),
                        'disposition' => 'inline',
                        'content_id' => $cid
                    ];
                }
                
                // Add standard (non-inline) attachments
                if (!empty($attachment)) {
                    if (is_string($attachment)) {
                        $attachment = [$attachment];
                    }
                    foreach ($attachment as $file) {
                        if (file_exists($file)) {
                            $emailData['attachments'][] = [
                                'content' => base64_encode(file_get_contents($file)),
                                'type' => mime_content_type($file),
                                'filename' => basename($file),
                                'disposition' => 'attachment'
                            ];
                        }
                    }
                }
      
                // POST the message to the SendGrid API
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, 'https://api.sendgrid.com/v3/mail/send');
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                curl_setopt($ch, CURLOPT_POST, 1);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($emailData));
                $headers = [
                    'Authorization: Bearer '.$this->agentKey,
                    'Content-Type: application/json'
                ];
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                $response = curl_exec($ch);

                // Transport failure
                if (curl_errno($ch)) {
                    throw new Exception('Error:' . curl_error($ch));
                } else {
                    // Decode the response (SendGrid returns an error body on failure)
                    $result = [];
                    if(is_array(json_decode($response, true))) {
                        $result = array_merge($result, json_decode($response, true));
                    }

                    if(!empty($debug)) {
                        echo '<pre>';
                        print_r($result);
                        echo '</pre>';
                    }
                }
                curl_close($ch);
                // Success when the response contains no errors
                if(empty($result['errors'])) {
                    return true;
                }
            }
        } catch (Exception $ex) {
            throw new Exception('Mail sending failed: ' . $ex->getMessage());
        }
        
        return false;
    }
}