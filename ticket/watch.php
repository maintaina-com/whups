<?php
/**
 * Copyright 2001-2002 Robert E. Coyle <robertecoyle@hotmail.com>
 * Copyright 2001-2011 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (BSD). If you
 * did not receive this file, see http://www.horde.org/licenses/bsdl.php.
 */

require_once dirname(__FILE__) . '/../lib/Application.php';
Horde_Registry::appInit('whups');

class AddListenerForm extends Horde_Form {

    function AddListenerForm(&$vars, $title = '')
    {
        parent::Horde_Form($vars, $title);

        $this->addHidden('', 'id', 'int', true, true);
        $this->addVariable(_("Email address to notify"), 'add_listener', 'email', true);
    }

}

class DeleteListenerForm extends Horde_Form {

    function DeleteListenerForm(&$vars, $title = '')
    {
        parent::Horde_Form($vars, $title);

        $this->addHidden('', 'id', 'int', true, true);
        $this->addVariable(_("Email address to remove"), 'del_listener', 'email', true);
    }

}

$ticket = Whups::getCurrentTicket();
$linkTags[] = $ticket->feedLink();
$vars = Horde_Variables::getDefaultVariables();
$vars->set('id', $id = $ticket->getId());
foreach ($ticket->getDetails() as $varname => $value) {
    $vars->add($varname, $value);
}

$addform = new AddListenerForm($vars, _("Add Watcher"));
$delform = new DeleteListenerForm($vars, _("Remove Watcher"));

if ($vars->get('formname') == 'addlistenerform') {
    if ($addform->validate($vars)) {
        $addform->getInfo($vars, $info);

        try {
            $whups_driver->addListener($id, '**' . $info['add_listener']);
            $ticket->notify(
                $info['add_listener'], false, array('**' . $info['add_listener']));
            $notification->push(
                sprintf(_("%s will be notified when this ticket is updated."), $info['add_listener']),
                'horde.success');
            $ticket->show();
        } catch (Whups_Exception $e) {
            $notification->push($e, 'horde.error');
        }
    }
} elseif ($vars->get('formname') == 'deletelistenerform') {
    if ($delform->validate($vars)) {
        $delform->getInfo($vars, $info);
        try {
            $whups_driver->deleteListener($id, '**' . $info['del_listener']);
            $notification->push(
                sprintf(_("%s will no longer receive updates for this ticket."), $info['del_listener']),
                'horde.success');
            $ticket->show();
        } catch (Whups_Exception $e) {
            $notification->push($e, 'horde.error');
        }
    }
}

$title = sprintf(_("Watchers for %s"), '[#' . $id . '] ' . $ticket->get('summary'));
require $registry->get('templates', 'horde') . '/common-header.inc';
require WHUPS_TEMPLATES . '/menu.inc';
require WHUPS_TEMPLATES . '/prevnext.inc';

$tabs = Whups::getTicketTabs($vars, $id);
echo $tabs->render('watch');

require WHUPS_TEMPLATES . '/ticket/watchers.inc';

$r = new Horde_Form_Renderer();

$addform->renderActive($r, $vars, 'watch.php', 'post');
echo '<br class="spacer" />';

$delform->renderActive($r, $vars, 'watch.php', 'post');
echo '<br class="spacer" />';

$form = new Whups_Form_TicketDetails($vars, $ticket, '[#' . $id . '] ' . $ticket->get('summary'));
$ticket->setDetails($vars);
$form->renderInactive($form->getRenderer(), $vars);

require $registry->get('templates', 'horde') . '/common-footer.inc';
