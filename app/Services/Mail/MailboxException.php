<?php

namespace App\Services\Mail;

/**
 * IMAP connection/auth failures. Messages are scrubbed of credentials
 * before they reach here.
 */
class MailboxException extends \RuntimeException {}
