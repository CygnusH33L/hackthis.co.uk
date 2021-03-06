<?php
    define("PAGE_PUBLIC", true);

    $custom_css = array('faq.scss');
    $custom_js = array('faq.js');
    require_once('init.php');
    if (isset($_GET['report']))
        $app->page->title = 'Report';
    else
        $app->page->title = 'Contact Us';
    $app->page->canonical = 'http://www.hackthis.co.uk/contact';
    require_once('header.php');


    if (isset($_GET['report'])):
        // Get report
        $report = $_GET['report'];
        $st = $app->db->prepare("SELECT * FROM mod_reports WHERE report_id = :rid LIMIT 1");
        $st->execute(array(':rid'=>$report));
        $report = $st->fetch();

        if ($report && $report->type == 'forum'):
            // Get post
            $post = $app->forum->getPost($report->about);
            if (!$post):
                $app->utils->message('Report not found');
            else:
                // Get thread
                $sql = "SELECT title, slug FROM forum_threads WHERE thread_id = :tid";
                $st = $app->db->prepare($sql);
                $st->execute(array(':tid'=>$post->thread_id));
                $post->thread = $st->fetch();

                if ($post->deleted == 0):
                    // Get changes
                    $sql = "SELECT old_value FROM forum_posts_audit WHERE post_id = :pid AND field='body' ORDER BY `time` DESC LIMIT 1";
                    $st = $app->db->prepare($sql);
                    $st->execute(array(':pid'=>$post->post_id));
                    $post->audit = $st->fetch();
                endif;
?>
        <h1>Report - <?=$report->subject;?></h1>
        <div class='report'>
            Your post in <a href='/forum/<?=$post->thread->slug;?>'><?=$app->parse($post->thread->title, false);?></a> has been <?=($post->deleted == 0)?'modified':'deleted';?>. The reason for the change:<br/>
            <div class='highlight'><?=$app->parse($report->body);?></div>
            <br/>
            The original post:<br/>
            <div class='highlight'><?=($post->deleted == 0)?$app->parse($post->audit->old_value):$app->parse($post->body);?></div><br/>
<?php
                if (isset($post->audit->old_value)):
?>
            Has been replaced by:<br/>
<?php
                    echo "<div class='highlight'>".$app->parse($post->body)."</div><br/>";
                endif;
                $app->utils->message('If you wish to discuss this report please open a <a href="/contact">ticket</a>', 'info');
?>
        </div>
<?php
            endif;
        elseif ($report && $report->type == 'forum_thread'):
            $sql = "SELECT title, slug FROM forum_threads WHERE thread_id = :tid";
            $st = $app->db->prepare($sql);
            $st->execute(array(':tid'=>$report->about));
            $thread = $st->fetch();

            if (!$thread):
                $app->utils->message('Report not found');
            else:
?>
        <h1>Report - <?=$report->subject;?></h1>
        <div class='report'>
            Your thread <a href='/forum/<?=$thread->slug;?>'><?=$app->parse($thread->title, false);?></a> has been deleted. The reason for the deletion:<br/>
            <div class='highlight'><?=$app->parse($report->body);?></div>
            <br/>
<?php
            $app->utils->message('If you wish to discuss this report please open a <a href="/contact">ticket</a>', 'info');
?>
        </div>
<?php
            endif;
        else:
            $app->utils->message('Report not found');
        endif;

        require('footer.php');
        die();
    endif;


    if (($app->user->loggedIn && $app->user->admin) && !isset($_GET['view'])):
        // Get all tickets
        $st = $app->db->prepare("SELECT `username`, mod_contact.`from`, `message_id`, `body`, COALESCE(latest.`sent`, mod_contact.`sent`) AS `last_sent`, COALESCE(`replies`, 0) AS `replies`, IF(mod_contact.`from` = COALESCE(latest.`from`,mod_contact.`from`),0,1) AS `new`
                                 FROM mod_contact
                                 LEFT JOIN users
                                 ON `from` = users.user_id
                                 LEFT JOIN (SELECT COUNT(message_id) AS `replies`, parent_id FROM mod_contact GROUP BY parent_id) replies
                                 ON replies.parent_id = mod_contact.message_id
                                 LEFT JOIN (SELECT `from`, `sent`, parent_id FROM mod_contact GROUP BY parent_id) latest
                                 ON latest.parent_id = mod_contact.message_id
                                 WHERE mod_contact.parent_id IS NULL
                                 ORDER BY last_sent DESC");
        $st->execute(array(':uid'=>$app->user->uid));
        $tickets = $st->fetchAll();
?>
    <h1>Tickets</h1>
        <table class='striped'>
            <thead><th>From</th><th>Message</th><th></th><th>Latest</th></thead>
            <tbody>
<?php
            foreach($tickets AS $message):
?> 
                    <tr>
<?php if ($message->username): ?>
                        <td><a href='/user/<?=$message->username;?>'><?=$message->username;?></a></td>
<?php else: ?>
                        <td><?=$app->utils->parse($message->from, false);?></td>
<?php endif; ?>
                        <td><a href='?view=<?=$message->message_id;?>'><?=$app->utils->parse($message->body, false, false, false, 50);?></a></td>
                        <td class='<?=!$message->new?'new':'old';?>'><?=$message->replies;?></td>
                        <td><time datetime="<?=date('c', strtotime($message->last_sent));?>"><?=$app->utils->timeSince($message->last_sent);?></time></td>
                    </tr>
<?php
            endforeach;
?>
            </tbody>
        </table>
<?php
    elseif (isset($_GET['view'])):
        $id = $_GET['view'];
        if (!$app->user->loggedIn || !$app->user->admin) {
            if (!$app->user->loggedIn && !isset($_GET['email'])) {
                $app->utils->message('Ticket not found');
                require('footer.php');
                die();
            } else {
                // Check user is in thread
                if ($app->user->loggedIn) {
                    $from = $app->user->uid;
                } else {
                    if ($app->utils->check_email($_GET['email'])) {
                        $from = $_GET['email'];
                    }
                }

                if (isset($from)) {
                    $st = $app->db->prepare("SELECT `message_id` FROM mod_contact
                        WHERE `from` = :uid AND message_id = :id");
                    $st->execute(array(':uid'=>$from, ':id'=>$id));
                    $first = $st->fetch();
                }

                if (!isset($first) || !$first) {
                    $app->utils->message('Ticket not found');
                    require('footer.php');
                    die();
                }
            }
        }

        $st = $app->db->prepare("SELECT `message_id`, `from`, username, COALESCE(users.email, `from`) AS `email` FROM mod_contact
            LEFT JOIN users ON users.user_id = mod_contact.from
            WHERE message_id = :id");
        $st->execute(array(':id'=>$id));
        $first = $st->fetch();

        if (isset($_POST['body'])) {
            $sent = false;
           
            if ($app->checkCSRFKey("contact", $_POST['token'])) {
                if ($app->user->loggedIn) {
                    $from = $app->user->uid;
                } else {
                    $from = $first->from;
                }

                if (!isset($error) && strlen($_POST['body']) < 5) {
                    $error = "Body content is too short";
                }

                if (!isset($error)) {
                    $st = $app->db->prepare("INSERT INTO mod_contact (`parent_id`, `from`, `body`, `javascript`, `browser`)
                       VALUES (:pid, :from, :body, null, null)");
                    $sent = $st->execute(array(':pid' =>$id, ':from'=>$from, ':body'=>$_POST['body']));

                    // Send email, maybe
                    if ($app->user->uid != $first->from) {
                        //If registered user
                        if ($first->username) {
                            // $body = "A reply has been added to a ticket you opened. To view the message please click the following link:<br/><a style='color:#ffffff; text-decoration: none;' href='https://www.hackthis.co.uk/contact?view={$id}'>https://www.hackthis.co.uk/contact?view={$id}</a>";

                            // Notify user
                            $app->notifications->add($first->from, 'mod_contact', $app->user->uid, $id);
                        } else {
                            // $body = "A reply has been added to a ticket you opened. To view the message please click the following link:<br/><a style='color:#ffffff; text-decoration: none;' href='https://www.hackthis.co.uk/contact?view={$id}&email={$first->email}'>https://www.hackthis.co.uk/contact?view={$id}&email={$first->email}</a>";
                        }

                        // $app->email->queue($first->email, "Ticket reply", $body, $first->username?$first->from:null);

                        $data = array('id' => $id, 'email' => ($first->username)?null:$first->email);
                        $app->email->queue($first->email, 'ticket_reply', json_encode($data), $first->username?$first->from:null);
                    }
                }
            }
        }

        $st = $app->db->prepare("SELECT users.username, mod_contact.* FROM mod_contact
                                 LEFT JOIN users ON users.user_id = mod_contact.from WHERE `message_id` = :id OR parent_id = :id ORDER BY `sent` ASC");
        $st->execute(array(':id'=>$id));
        $messages = $st->fetchAll();
?>
        <a href='/contact' class='button right'>Back to tickets</a>
        <h1>Ticket #<?=$id;?></h1>
        <table class='striped ticket-conversation'>
            <tbody>
<?php
            $n = 0;
            foreach($messages AS $message):
                $n++;
?> 
                    <tr>
                        <td>
                            <time class='right' datetime="<?=date('c', strtotime($message->sent));?>"><?=$app->utils->timeSince($message->sent);?></time>
                            <strong class='white' style='display: inline-block; margin-bottom: 4px'>
<?php if ($message->username): ?>
                            <a href='/user/<?=$message->username;?>'><?=$message->username;?></a>
<?php else: ?>
                            <?=$app->utils->parse($message->from, false);?>
<?php endif; ?>
                            </strong>
<?php
    if ($n == 1):
        if ($message->browser == "Firefox")
            echo "<i class='icon-firefox-2'></i>";
        else if ($message->browser == "IE")
            echo "<i class='icon-IE'></i>";
        else if ($message->browser == "Chrome")
            echo "<i class='icon-chrome'></i>";
        else if ($message->browser == "Opera")
            echo "<i class='icon-opera'></i>";
        else if ($message->browser == "Safari")
            echo "<i class='icon-safari'></i>";

        if ($message->javascript == 1)
            echo "<i class='icon-code js-on'></i>";
        else if ($message->javascript == 0)
            echo "<i class='icon-code js-off'></i>";
    endif;
?>
                            <br/>
                            <?=$app->utils->parse($message->body);?>
                        </td>
                    </tr>
<?php
            endforeach;
?>
            </tbody>
        </table>
        <br/>
<?php
        if (isset($sent) && $sent):
            $app->utils->message('Message sent', 'good');
        else:
            if (isset($error))
                $app->utils->message($error);
            else if (isset($sent) && $sent === false)
                $app->utils->message('Error sending message');
        endif;
?>
        <form method="POST">
            <fieldset>
                <?php include('elements/wysiwyg.php'); ?>

                <input type="hidden" value="false" name="js">
                <input type="hidden" value="<?=$app->generateCSRFKey("contact");?>" name="token">
                <input type="submit" value="Send" class="button">
            </fieldset>
        </form>
<?php
    else:

        if (isset($_GET['send']) && isset($_POST['body'])) {
            $sent = false;
           
            if ($app->checkCSRFKey("contact", $_POST['token'])) {
                $browser = $app->utils->get_browser();
                
                $js = $_POST['js'];
                if ($js == 'true') $js = 1;
                else if ($js == 'false') $js = 0;
                else $js = 0;

                if ($app->user->loggedIn) {
                    $from = $app->user->uid;
                } else {
                    if ($app->utils->check_email($_POST['email'])) {
                        $from = $_POST['email'];
                    } else {
                        $error = "Invalid email address";
                    }
                }

                if (!isset($error) && strlen($_POST['body']) < 5) {
                    $error = "Body content is too short";
                }

                if (!isset($error)) {
                    $st = $app->db->prepare("INSERT INTO mod_contact (`from`, `body`, `javascript`, `browser`)
                       VALUES (:from, :body, :js, :browser)");
                    $sent = $st->execute(array(':from'=>$from, ':body'=>$_POST['body'], ':js'=>$js, ':browser'=>$browser));
                }
            }
        }

        // Get existing conversations
        if ($app->user->loggedIn) {
            $st = $app->db->prepare("SELECT `message_id`, `body`, mod_contact.`sent`, COALESCE(latest.`sent`, mod_contact.`sent`) AS `last_sent`, COALESCE(`replies`, 0) AS `replies`, IF(mod_contact.`from` = COALESCE(latest.`from`,mod_contact.`from`),0,1) AS `new`
                                     FROM mod_contact
                                     LEFT JOIN (SELECT COUNT(message_id) AS `replies`, parent_id FROM mod_contact GROUP BY parent_id) replies
                                     ON replies.parent_id = mod_contact.message_id
                                     LEFT JOIN (SELECT `from`, `sent`, parent_id FROM mod_contact ORDER BY `sent` DESC LIMIT 1) latest
                                     ON latest.parent_id = mod_contact.message_id
                                     WHERE mod_contact.`from` = :uid AND mod_contact.parent_id IS NULL
                                     ORDER BY `last_sent` DESC");
            $st->execute(array(':uid'=>$app->user->uid));
            $previous = $st->fetchAll();
        }
?>
        <h1>Contact Us</h1>

        <p style="text-align: justify; padding-bottom: 16px; margin: 0px">
            The easiest way to contact us is to use the form provided below. We will respond to you as quickly as we can. Feel free to say hi or send us any comments or suggestions you have...good or bad.
        </p>

<?php
        if (isset($sent) && $sent):
            $app->utils->message('Message sent', 'good');
        else:
            if (isset($error))
                $app->utils->message($error);
            else if (isset($sent) && $sent === false)
                $app->utils->message('Error sending message');
?>
        <form action="?send" method="POST">
            <fieldset>
<?php
            if (!$app->user->loggedIn):
?>
                <label for="email">Email address:</label><br/>
                <input name="email" class='short'/><br/>
<?php
            endif;
?>

                <?php include('elements/wysiwyg.php'); ?>

                <input type="hidden" value="false" name="js">
                <input type="hidden" value="<?=$app->generateCSRFKey("contact");?>" name="token">
                <input type="submit" value="Send" class="button">
            </fieldset>
        </form>
<?php
        endif; 

        if (isset($previous) && count($previous)):
?>
        <p>
            <br/>
            <h2>Previous tickets</h2>
            <table class='striped'>
                <thead><th>Message</th><th></th><th>Latest</th></thead>
                <tbody>
<?php
            foreach($previous AS $message):
?> 
                    <tr>
                        <td><a href='?view=<?=$message->message_id;?>'><?=$app->utils->parse($message->body, false, false, false, 50);?></a></td>
                        <td class='<?=$message->new?'new':'old';?>'><?=$message->replies;?></td>
                        <td><time datetime="<?=date('c', strtotime($message->last_sent));?>"><?=$app->utils->timeSince($message->last_sent);?></time></td>
                    </tr>
<?php
            endforeach;
?>
            </tbody>
            </table>
        </p>
<?php
        endif;
    endif;
    require_once('footer.php');
?>