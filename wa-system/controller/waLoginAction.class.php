<?php

abstract class waLoginAction extends waViewAction
{
    public function execute()
    {
        $this->view->setOptions(array('left_delimiter' => '{', 'right_delimiter' => '}'));

        // Set locale if specified
        if ( ( $locale = waRequest::get('locale')) || ( $locale = wa()->getStorage()->read('locale'))) {
            wa()->setLocale($locale);
            wa()->getStorage()->write('locale', $locale);
        }

        // load webasyst locale and make it default for [``] in templates
        wa('webasyst')->getConfig()->setLocale(wa()->getLocale(), true);

        $title = $this->getTitle();
        $title_style = $this->getTitleStyle();

        // Password recovery form to enter login/email
        if (waRequest::request('forgot')) {
            $title .= ' - '._ws('Password recovery');
            if (waRequest::method() == 'post' && !waRequest::post('ignore')) {
                $this->forgot();
            }
            $this->view->assign('type', 'forgot');
        }
        // Password recovery form to enter new password
        else if (waRequest::get('password') && waRequest::get('key')) {
            $this->recovery();
            $this->view->assign('type', 'password');
        }
        // Voodoo magic: reload page when user performs an AJAX request after session died.
        else if (waRequest::isXMLHttpRequest() && waRequest::param('secure')) {
            //
            // The idea behind this is quite complicated.
            //
            // When browser expects JSON and gets this response then the error handler is called.
            // Default error handler (see wa.core.js) looks for the wa-session-expired header
            // and reloads the page when it's found.
            //
            // On the other hand, when browser expects HTML, it's most likely to insert it to the DOM.
            // In this case <script> gets executed and browser reloads the whole layout to show login page.
            // (This is also the reason to use 200 HTTP response code here: no error handler required at all.)
            //
            header('wa-session-expired: 1');
            echo _ws('Session has expired. Please reload current page and log in again.').'<script>window.location.reload();</script>';
            exit;
        }
        // Enter login/email and password
        else {
            // Save referrer to session
            $ref = waRequest::server('HTTP_REFERER');
            if(waRequest::get('back_to') && $ref) {
                wa()->getStorage()->write('login_back_on_cancel', $ref);
            } else if (!$ref) {
                wa()->getStorage()->remove('login_back_on_cancel');
            }
            $this->view->assign('type', '');
            $this->view->assign('back_on_cancel', wa()->getStorage()->read('login_back_on_cancel'));
            $this->view->assign('login', waRequest::post('login', $this->getStorage()->read('auth_login')));
        }

        $this->view->assign('title', $title);
        $this->view->assign('title_style', $title_style);

        $app_settings_model = new waAppSettingsModel();
        $background = $app_settings_model->get('webasyst', 'auth_form_background');
        $stretch = $app_settings_model->get('webasyst', 'auth_form_background_stretch');
        if ($background) {
            $background = 'wa-data/public/webasyst/'.$background;
        }
        $this->view->assign('stretch', $stretch);
        $this->view->assign('background', $background);

        $this->view->assign('remember_enabled', $app_settings_model->get('webasyst', 'rememberme', 1));

        $auth = $this->getAuth();
        $authorized = false;
        try {
            // Already authorized from session?
            if ($this->getUser()->isAuth()) {
                if (!$auth->getOption('is_user') || $this->getUser()->get('is_user')) {
                    $authorized = true;
                }
            }
            // Try to authorize from POST or cookies
            if (!$authorized && $auth->auth()) {
                $authorized = true;
            }

            if ($authorized) {
                // Final check: is user banned?
                if (wa()->getUser()->get('is_banned')) {
                    wa()->getAuth()->clearAuth();
                    throw new waException(_w('Access denied'));
                }

                // Proceed with successfull authorization
                $this->getStorage()->remove('auth_login');
                $this->afterAuth();
                exit;
            }
        } catch (waException $e) {
            $this->view->assign('error', $e->getMessage());
        }

        $this->view->assign('options', $auth->getOptions());

        if ($this->template === null) {
            if (waRequest::isMobile()) {
                $this->template = 'LoginMobile.html';
            } else {
                $this->template = 'Login.html';
            }
            $this->template = wa()->getAppPath('templates/actions/login/', 'webasyst').$this->template;
        }
    }

    public function getTitle()
    {
        if ( ( $title = $this->getConfig()->getOption('login_form_title'))) {
            return waLocale::fromArray($title);
        }
        return wa()->getSetting('name', 'Webasyst', 'webasyst');
    }

    public function getTitleStyle()
    {
        return $this->getConfig()->getOption('login_form_title_style');
    }

    /**
     * @return waAuth
     */
    protected function getAuth()
    {
        return waSystem::getInstance()->getAuth();
    }

    protected function forgot()
    {
        $login = waRequest::post('login');
        $contact_model = new waContactModel();
        $auth = $this->getAuth();
        $is_user = $auth->getOption('is_user');
        if (strpos($login, '@')) {
            $sql = "SELECT c.* FROM wa_contact c
            JOIN wa_contact_emails e ON c.id = e.contact_id
            WHERE ".($is_user ? "c.is_user = 1 AND " : "")."e.email LIKE s:email AND e.sort = 0
            ORDER BY c.id LIMIT 1";
            $contact_info = $contact_model->query($sql, array('email' => $login))->fetch();
            $this->view->assign('email', true);
        } else {
            $contact_info = $contact_model->getByField('login', $login);
        }
        // if contact found and it is user
        if ($contact_info && (!$is_user || $contact_info['is_user'])) {
            $contact = new waContact($contact_info['id']);
            $contact->setCache($contact_info);

            // Is user banned?
            if ($contact->get('is_banned')) {
                $this->view->assign('error', _w('Password recovery for this email has been banned.'));
            } else
            // get defaul email to send mail
            if ($to = $contact->get('email', 'default')) {
                // Send message in user's language
                if ($contact['locale']) {
                    wa()->setLocale($contact['locale']);
                    waLocale::loadByDomain('webasyst', wa()->getLocale());
                }

                // generate unique key and save in contact settings
                $hash = md5(uniqid(null, true));
                $contact_settings_model = new waContactSettingsModel();
                $contact_settings_model->set($contact['id'], 'webasyst', 'forgot_password_hash', $hash);
                $hash = substr($hash, 0, 16).$contact['id'].substr($hash, -16);
                // url to recovery password
                if ($this->getApp() === 'webasyst') {
                    $url = wa()->getAppUrl().'?password=1&key='.$hash;
                } else {
                    $url = $this->getConfig()->getCurrentUrl();
                    $url = preg_replace('/\?.*$/i', '', $url);
                    $url .= '?password=1&key='.$hash;
                }
                $this->view->assign('url', $this->getConfig()->getHostUrl().$url);
                // send email
                $subject = _ws("Recovering password");
                $template_file = $this->getConfig()->getConfigPath('mail/RecoveringPassword.html', true, 'webasyst');
                if (file_exists($template_file)) {
                    $body = $this->view->fetch($template_file);
                } else {
                    $body = $this->view->fetch(wa()->getAppPath('templates/mail/RecoveringPassword.html', 'webasyst'));
                }
                $this->view->clearAllAssign();
                $mailer = new waMail();
                if ($mailer->send($to, $subject, $body)) {
                    $this->view->assign('success', true);
                } else {
                    $this->view->assign('error', _ws('Sorry, we can not recover password for this login name or email. Please refer to your system administrator.'));
                }
            } else {
                $this->view->assign('error', _w('Sorry, we can not recover password for this login name or email. Please refer to your system administrator.'));
            }
        } else {
            if ($auth->getOption('login') == 'email') {
                $this->view->assign('error', _w('No user with this email has been found.'));
            } else {
                $this->view->assign('error', _w('No user with this login name or email has been found.'));
            }
        }
    }

    protected function recovery()
    {
        $hash = waRequest::get('key');
        $error = true;
        if ($hash && strlen($hash) > 32) {
            $contact_id = substr($hash, 16, -16);
            $contact_settings_model = new waContactSettingsModel();
            $contact_hash = $contact_settings_model->getOne($contact_id, 'webasyst', 'forgot_password_hash');
            $contact_hash = substr($contact_hash, 0, 16).$contact_id.substr($contact_hash, -16);
            $contact_model = new waContactModel();
            $contact_info = $contact = $contact_model->getById($contact_id);
            if ($contact_info && $hash === $contact_hash)
            {
                // Show the form in user's language
                if ($contact_info['locale']) {
                    wa()->setLocale($contact_info['locale']);
                    waLocale::loadByDomain('webasyst', wa()->getLocale());
                }

                $auth = $this->getAuth();
                if ($auth->getOption('login') == 'login') {
                    $login = $contact_info['login'];
                } elseif ($auth->getOption('login') == 'email') {
                    $email_model = new waContactEmailsModel();
                    $email = $email_model->getByField(array('contact_id' => $contact_id, 'sort' => 0));
                    $login = $email['email'];
                }
                $this->view->assign('login', $login);
                if (waRequest::method() == 'post') {
                    $password = waRequest::post('password');
                    $password_confirm = waRequest::post('password_confirm');
                    if ($password === $password_confirm) {
                        $user = new waUser($contact_id);
                        $user['password'] = $password;
                        $user->save();
                        $contact_settings_model->delete($contact_id, 'webasyst', 'forgot_password_hash');
                        // auth
                        $this->getAuth()->auth(array(
                            'login' => $login,
                            'password' => $password
                        ));
                        $this->redirect(wa()->getAppUrl());
                    } else {
                        $this->view->assign('error', _w('Passwords do not match'));
                    }
                }
                $error = false;
            }
        }
        if ($error) {
            $this->redirect($this->getConfig()->getBackendUrl(true));
        }
    }

    abstract protected function afterAuth();
}
