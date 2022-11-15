<?php
// t_login.php -- HotCRP tests
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Login_Tester {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var UserStatus
     * @readonly */
    public $us1;
    /** @var Contact
     * @readonly */
    public $user_chair;
    /** @var ?\mysqli
     * @readonly */
    public $cdb;

    function __construct(Conf $conf) {
        $this->conf = $conf;
        $this->us1 = new UserStatus($conf->root_user());
        $this->user_chair = $conf->checked_user_by_email("chair@_.com");
        $this->cdb = $conf->contactdb();
    }

    function test_setup() {
        $removables = ["newuser@_.com", "scapegoat2@baa.com", "firstchair@_.com"];
        $this->conf->qe("delete from ContactInfo where email?a", $removables);
        if ($this->cdb !== null) {
            Dbl::qe($this->cdb, "delete from ContactInfo where email?a", $removables);
        }
    }

    function test_login() {
        $email = "newuser@_.com";
        $this->conf->invalidate_caches(["users" => true, "cdb" => true]);

        $user = Contact::make($this->conf);
        $qreq = TestRunner::make_qreq($user, "newaccount?email={$email}", "POST");
        $info = LoginHelper::new_account_info($this->conf, $qreq);
        xassert_eqq($info["ok"], true);
        $u = $info["user"];
        xassert($u->contactId > 0);
        xassert(!$this->cdb || $u->cdb_user()->contactDbId > 0);
        $signinp = new Signin_Page;
        $prep = $signinp->mail_user($this->conf, $info);
        // reset capability set, is in cdb
        xassert(is_string($prep->reset_capability));
        xassert(str_starts_with($prep->reset_capability, "hcpw"));

        $this->conf->invalidate_caches(["users" => true, "cdb" => true]);

        $user = Contact::make_email($this->conf, $email);
        $qreq = TestRunner::make_qreq($user, "resetpassword?email={$email}", "POST");
        $qreq->set_req("password", $prep->reset_capability);
        xassert_eqq(Signin_Page::check_password_as_reset_code($user, $qreq),
                    $prep->reset_capability);

        $this->conf->invalidate_caches(["users" => true]);

        $user = Contact::make_email($this->conf, $email);
        $qreq = TestRunner::make_qreq($user, "resetpassword?email={$email}", "POST");
        $qreq->set_req("resetcap", $prep->reset_capability);
        $qreq->set_req("password", "newuserpassword!");
        $qreq->set_req("password2", "newuserpassword!");
        $signinp = new Signin_Page;
        $result = null;
        try {
            $signinp->reset_request($user, $qreq);
        } catch (Redirection $redir) {
            $result = $redir;
        }
        xassert(!!$result);
        xassert(user($email)->check_password("newuserpassword!"));

        if ($this->cdb) {
            $this->conf->invalidate_caches(["users" => true, "cdb" => true]);
            $this->conf->qe("delete from ContactInfo where email=?", $email);

            $user = Contact::make($this->conf);
            xassert_eqq($user->contactId, 0);
            xassert_eqq($user->contactDbId, 0);
            $qreq = TestRunner::make_qreq($user, "signin?email={$email}&password=newuserpassword!", "POST");
            $info = LoginHelper::login_info($this->conf, $qreq);
            xassert_eqq($info["ok"], true);
            $info = LoginHelper::login_complete($info, $qreq);
            xassert_eqq($info["ok"], true);
            $user = $info["user"];
            xassert($user instanceof Contact);
            xassert_eqq($user->email, $email);
            xassert_eqq($user->contactId, 0);
            xassert_neqq($user->contactDbId, 0);
            $user->ensure_account_here();
            xassert_neqq($user->contactId, 0);
            xassert_eqq($user->contactDbId, 0);
        }
    }

    function test_login_placeholder() {
        $email = "scapegoat2@baa.com";
        Contact::make_keyed($this->conf, [
            "email" => $email,
            "disablement" => Contact::DISABLEMENT_PLACEHOLDER
        ])->store();

        $this->conf->invalidate_caches(["users" => true, "cdb" => true]);

        // `newaccount` request
        $user = Contact::make($this->conf);
        $qreq = TestRunner::make_qreq($user, "newaccount?email={$email}", "POST");
        $info = LoginHelper::new_account_info($this->conf, $qreq);
        xassert_eqq($info["ok"], true);
        $u = $info["user"];
        xassert($u->contactId > 0);
        xassert(!$this->cdb || $u->cdb_user()->contactDbId > 0);
        $signinp = new Signin_Page;
        $prep = $signinp->mail_user($this->conf, $info);
        // reset capability set, is in cdb
        xassert(is_string($prep->reset_capability));
        xassert(str_starts_with($prep->reset_capability, "hcpw"));

        // but user is still a placeholder
        $u = $this->conf->checked_user_by_email($email);
        xassert(!!$u);
        xassert_eqq($u->disablement, Contact::DISABLEMENT_PLACEHOLDER);
        if ($this->cdb) {
            $u = $this->conf->checked_cdb_user_by_email($email);
            xassert(!!$u);
            xassert_eqq($u->disablement, Contact::DISABLEMENT_PLACEHOLDER);
        }

        $this->conf->invalidate_caches(["users" => true, "cdb" => true]);

        // `resetpassword` request with capability
        $user = Contact::make_email($this->conf, $email);
        $qreq = TestRunner::make_qreq($user, "resetpassword?email={$email}", "POST");
        $qreq->set_req("resetcap", $prep->reset_capability);
        $qreq->set_req("password", "newuserpassword!");
        $qreq->set_req("password2", "newuserpassword!");
        $signinp = new Signin_Page;
        $result = null;
        try {
            $signinp->reset_request($user, $qreq);
        } catch (Redirection $redir) {
            $result = $redir;
        }
        xassert(!!$result);
        xassert(user($email)->check_password("newuserpassword!"));

        // user is no longer a placeholder
        $u = $this->conf->checked_user_by_email($email);
        xassert(!!$u);
        xassert_eqq($u->disablement, 0);
        if ($this->cdb) {
            $u = $this->conf->checked_cdb_user_by_email($email);
            xassert(!!$u);
            xassert_eqq($u->disablement, 0);
        }
    }

    function test_login_first_user() {
        $email = "firstchair@_.com";
        $this->conf->save_setting("setupPhase", 1);
        $this->conf->invalidate_caches(["users" => true, "cdb" => true]);

        $user = Contact::make($this->conf);
        $qreq = TestRunner::make_qreq($user, "newaccount?email={$email}", "POST");
        $info = LoginHelper::new_account_info($this->conf, $qreq);
        xassert_eqq($info["ok"], true);
        $u = $info["user"];
        xassert($u->contactId > 0);
        $signinp = new Signin_Page;
        $prep = $signinp->mail_user($this->conf, $info);
        // reset capability set, is in cdb
        xassert(is_string($prep->reset_capability));
        xassert(str_starts_with($prep->reset_capability, "hcpw"));

        $this->conf->invalidate_caches(["users" => true, "cdb" => true]);

        $user = Contact::make_email($this->conf, $email);
        $qreq = TestRunner::make_qreq($user, "resetpassword?email={$email}", "POST");
        $qreq->set_req("password", $prep->reset_capability);
        xassert_eqq(Signin_Page::check_password_as_reset_code($user, $qreq),
                    $prep->reset_capability);

        $this->conf->invalidate_caches(["users" => true, "cdb" => true]);

        $user = Contact::make_email($this->conf, $email);
        $qreq = TestRunner::make_qreq($user, "resetpassword?email={$email}", "POST");
        $qreq->set_req("resetcap", $prep->reset_capability);
        $qreq->set_req("password", "newuserpassword!");
        $qreq->set_req("password2", "newuserpassword!");
        $signinp = new Signin_Page;
        $result = null;
        try {
            $signinp->reset_request($user, $qreq);
        } catch (Redirection $redir) {
            $result = $redir;
        }
        xassert(!!$result);

        $u = user($email);
        xassert($u->check_password("newuserpassword!"));
        xassert($u->privChair);
    }
}
