<?php
/**
 * Whups mail processing library.
 *
 * Copyright 2004-2016 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (BSD). If you
 * did not receive this file, see http://www.horde.org/licenses/bsdl.php.
 *
 * @author  Jason M. Felice <jason.m.felice@gmail.com>
 * @author  Jan Schneider <jan@horde.org>
 * @package Whups
 */
class Whups_Mail
{
    /**
     * Parse a MIME message and create a new ticket.
     *
     * @param string $text       This is the full text of the MIME message.
     * @param array $info        An array of information for the new ticket.
     *                           This should include:
     *                           - 'queue'    => queue id
     *                           - 'type'     => type id
     *                           - 'state'    => state id
     *                           - 'priority' => priority id
     *                           - 'ticket'   => ticket id (prevents creation
     *                                           of new tickets)
     * @param string $auth_user  This will be the Horde user that creates the
     *                           ticket. If null, we will try to deduce from
     *                           the message's From: header. We do NOT default
     *                           to $GLOBALS['registry']->getAuth().
     *
     * @return Whups_Ticket  Ticket.
     */
    static public function processMail($text, array $info, $auth_user = null)
    {
        global $conf;

        $message = Horde_Mime_Part::parseMessage($text);

        if (preg_match("/^(.*?)\r?\n\r?\n/s", $text, $matches)) {
            $hdrText = $matches[1];
        } else {
            $hdrText = $text;
        }
        $headers = Horde_Mime_Headers::parseHeaders($hdrText);

        // If this message was generated by Whups, don't process it.
        if ($headers->getValue('X-Whups-Generated')) {
            return true;
        }

        // Try to avoid bounces, auto-replies, and mailing list responses.
        $from = $headers->getValue('from');
        if (strpos($headers->getValue('Content-Type'), 'multipart/report') !== false ||
            stripos($from, 'mailer-daemon@') !== false ||
            stripos($from, 'postmaster@') !== false ||
            !is_null($headers->getValue('X-Failed-Recipients')) ||
            !is_null($headers->getValue('X-Autoreply-Domain')) ||
            $headers->getValue('Auto-Submitted') == 'auto-replied' ||
            $headers->getValue('Precedence') == 'auto_reply' ||
            $headers->getValue('X-Precedence') == 'auto_reply' ||
            $headers->getValue('X-Auto-Response-Suppress') == 'All' ||
            $headers->getValue('X-List-Administrivia') == 'Yes') {
            return true;
        }

        // Use the message subject as the ticket summary.
        $info['summary'] = trim($headers->getValue('subject'));
        if (empty($info['summary'])) {
            $info['summary'] = _("[No Subject]");
        }

        // Format the message into a comment.
        $comment = _("Received message:") . "\n\n";
        if (!empty($GLOBALS['conf']['mail']['include_headers'])) {
            foreach ($headers->toArray(array('nowrap' => true)) as $name => $vals) {
                if (!in_array(strtolower($name), array('subject', 'from', 'to', 'cc', 'date'))) {
                    if (is_array($vals)) {
                        foreach ($vals as $val) {
                            $comment .= $name . ': ' . $val . "\n";
                        }
                    } else {
                        $comment .= $name . ': ' . $vals . "\n";
                    }
                }
            }

            $comment .= "\n";
        }

        // Look for the body part.
        $body_id = $message->findBody();
        if ($body_id) {
            $part = $message->getPart($body_id);
            $content = Horde_String::convertCharset(
                $part->getContents(), $part->getCharset(), 'UTF-8');
            switch ($part->getType()) {
            case 'text/plain':
                $comment .= $content;
                break;
            case 'text/html':
                $comment .= Horde_Text_Filter::filter(
                    $content, array('Html2text'), array(array('width' => 0)));;
                break;
            default:
                $comment .= _("[ Could not render body of message. ]");
                break;
            }
        } else {
            $comment .= _("[ Could not render body of message. ]");
        }

        $info['comment'] = $comment . "\n";

        // Try to determine the Horde user for creating the ticket.
        if (empty($auth_user)) {
            $tmp = new Horde_Mail_Rfc822_Address($from);
            $auth_user = self::_findAuthUser($tmp->bare_address);
        }
        $author = $auth_user;

        if (empty($auth_user) && !empty($info['default_auth'])) {
            $auth_user = $info['default_auth'];
            if (!empty($from)) {
                $info['user_email'] = $from;
            }
        }

        if (empty($auth_user) && !empty($conf['mail']['username'])) {
            $auth_user = $conf['mail']['username'];
            if (!empty($from)) {
                $info['user_email'] = $from;
            }
        }

        // Authenticate as the correct Horde user.
        if (!empty($auth_user) && $auth_user != $GLOBALS['registry']->getAuth()) {
            $GLOBALS['registry']->setAuth($auth_user, array());
        }

        // Attach message.
        $attachments = array();
        if (!empty($GLOBALS['conf']['mail']['attach_message'])) {
            $tmp_name = Horde::getTempFile('whups');
            $fp = @fopen($tmp_name, 'wb');
            if (!$fp) {
                throw new Whups_Exception(
                    sprintf('Cannot open file %s for writing.', $tmp_name));
            }
            fwrite($fp, $text);
            fclose($fp);
            $attachments[] = array(
                'name' => _("Original Message") . '.eml',
                'tmp_name' => $tmp_name);
        }

        // Extract attachments.
        $dl_list = array_slice(array_keys($message->contentTypeMap()), 1);
        foreach ($dl_list as $key) {
            $part = $message->getPart($key);
            if (($key == $body_id && $part->getType() == 'text/plain') ||
                $part->getType() == 'multipart/alternative' ||
                $part->getType() == 'multipart/mixed') {
                continue;
            }
            $tmp_name = Horde::getTempFile('whups');
            $fp = @fopen($tmp_name, 'wb');
            if (!$fp) {
                throw new Whups_Exception(
                    sprintf('Cannot open file %s for writing.', $tmp_name));
            }
            fwrite($fp, $part->getContents());
            fclose($fp);
            $part_name = $part->getName(true);
            if (!$part_name) {
                $ptype = $part->getPrimaryType();
                switch ($ptype) {
                case 'multipart':
                case 'application':
                    $part_name = sprintf(_("%s part"), ucfirst($part->getSubType()));
                    break;
                default:
                    $part_name = sprintf(_("%s part"), ucfirst($ptype));
                    break;
                }
                if ($ext = Horde_Mime_Magic::mimeToExt($part->getType())) {
                    $part_name .= '.' . $ext;
                }
            }
            $attachments[] = array(
                'name' => $part_name,
                'tmp_name' => $tmp_name);
        }

        // See if we can match this message to an existing ticket.
        if ($ticket = self::_findTicket($info)) {
            $ticket->change('comment', $info['comment']);
            $ticket->change('comment-email', $from);
            if ($attachments) {
                $ticket->change('attachments', $attachments);
            }
            $ticket->commit($author);
        } elseif (!empty($info['ticket'])) {
            // Didn't match an existing ticket though a ticket number had been
            // specified.
            throw new Whups_Exception(
                sprintf(_("Could not find ticket \"%s\"."), $info['ticket']));
        } else {
            if (!empty($info['guess-queue'])) {
                // Try to guess the queue name for the new ticket from the
                // message subject.
                $queues = $GLOBALS['whups_driver']->getQueues();
                foreach ($queues as $queueId => $queueName) {
                    if (preg_match('/\b' . preg_quote($queueName, '/') . '\b/i',
                                   $info['summary'])) {
                        $info['queue'] = $queueId;
                        break;
                    }
                }
            }
            $info['attachments'] = $attachments;

            // Create a new ticket.
            $ticket = Whups_Ticket::newTicket($info, $author);
        }
    }

    /**
     * Returns the ticket number matching the provided information.
     *
     * @param array $info  A hash with ticket information.
     *
     * @return integer  The ticket number if has been passed in the subject,
     *                  false otherwise.
     */
    static protected function _findTicket(array $info)
    {
        if (!empty($info['ticket'])) {
            $ticketnum = $info['ticket'];
        } elseif (preg_match('/\[[\w\s]*#(\d+)\]/', $info['summary'], $matches)) {
            $ticketnum = $matches[1];
        } else {
            return false;
        }

        try {
            return Whups_Ticket::makeTicket($ticketnum);
        } catch (Whups_Exception $e) {
            return false;
        }
    }

    /**
     * Searches the From: header for an email address contained in one
     * of our users' identities.
     *
     * @param string $from  The From address.
     *
     * @return string  The Horde user name that matches the headers' From:
     *                 address or null if the users can't be listed or no
     *                 match has been found.
     */
    static protected function _findAuthUser($from)
    {
        $auth = $GLOBALS['injector']->getInstance('Horde_Core_Factory_Auth')->create();

        if ($auth->hasCapability('list')) {
            foreach ($auth->listUsers() as $user) {
                $identity = $GLOBALS['injector']
                    ->getInstance('Horde_Core_Factory_Identity')
                    ->create($user);
                $addrs = $identity->getAll('from_addr');
                foreach ($addrs as $addr) {
                    if (strcasecmp($from, $addr) == 0) {
                        return $user;
                    }
                }
            }
        }

        return false;
    }

}
