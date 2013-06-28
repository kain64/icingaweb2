<?php

// {{{ICINGA_LICENSE_HEADER}}}
/**
 * This file is part of Icinga 2 Web.
 *
 * Icinga 2 Web - Head for multiple monitoring backends.
 * Copyright (C) 2013 Icinga Development Team
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
 *
 * @copyright 2013 Icinga Development Team <info@icinga.org>
 * @license   http://www.gnu.org/licenses/gpl-2.0.txt GPL, version 2
 * @author    Icinga Development Team <info@icinga.org>
 */
// {{{ICINGA_LICENSE_HEADER}}}

namespace Icinga\Authentication\Backend;

use Icinga\Util\Crypto as Crypto;
use Icinga\Authentication\User as User;
use Icinga\Authentication\UserBackend;
use Icinga\Authentication\Credentials;
use Icinga\Authentication;


/**
 * Authenticates users using a sql db as backend.
 * @package Icinga\Authentication\Backend
 */
class DbUserBackend implements UserBackend {

    private $db;

    private $userTable;

    private $USER_NAME_COLUMN   = "user_name",
            $FIRST_NAME_COLUMN  = "first_name",
            $LAST_NAME_COLUMN   = "last_name",
            $LAST_LOGIN_COLUMN  = "last_login",
            $SALT_COLUMN        = "salt",
            $PASSWORD_COLUMN    = "password",
            $ACTIVE_COLUMN      = "active",
            $DOMAIN_COLUMN      = "domain",
            $EMAIL_COLUMN       = "email";

    /*
     * maps the configuration dbtypes to the corresponding Zend-PDOs
     */
    private $dbTypeMap = Array(
        "mysql" => "PDO_MYSQL",
        "pgsql" => "PDO_PGSQL"
    );

    /**
     * Creates a DbUserBackend with the given configuration.
     * @param $config The configuration-object containing the members host,user,password,db
     */
    public function __construct($config){
        $this->dbtype = $config->dbtype;
        $this->userTable = $config->table;

        $this->db = \Zend_Db::factory(
            $this->dbTypeMap[$config->dbtype],
            array(
                'host'      => $config->host,
                'username'  => $config->user,
                'password'  => $config->password,
                'dbname'    => $config->db
        ));

        /*
         * Test the connection settings
         */
        $this->db->getConnection();
        $this->db->select()->from($this->userTable,new \Zend_Db_Expr("TRUE"));
    }

    /**
     * Checks if the user in the given Credentials-object is available.
     * @param Credentials $credentials The login credentials of the user.
     * @return boolean True when the username is known and currently active.
     */
    public function hasUsername(Credentials $credential)
    {
        $user = $this->getUserByName($credential->getUsername());
        return !empty($user);
    }

    /**
     * Authenticate a user with the given credentials.
     * @param Credentials $credentials
     * @return User|null The authenticated user or Null.
     */
    public function authenticate(Credentials $credential){
        $this->db->getConnection();
        $res = $this->db
            ->select()->from($this->userTable)
                ->where($this->USER_NAME_COLUMN.' = ?',$credential->getUsername())
                ->where($this->ACTIVE_COLUMN.   ' = ?',true)
                ->where($this->PASSWORD_COLUMN. ' = ?',Crypto::hashPassword(
                        $credential->getPassword(),
                        $this->getUserSalt($credential->getUsername())
                    ))
                ->query()->fetch();
        if(!empty($res)){
            $this->updateLastLogin($credential->getUsername());
            return $this->createUserFromResult($res);
        }
    }

    /**
     * Updates the timestamp containing the time of the last login for
     * the user with the given username.
     * @param $username The login-name of the user.
     */
    private function updateLastLogin($username){
        $this->db->getConnection();
        $this->db->update(
            $this->userTable,
            array(
                $this->LAST_LOGIN_COLUMN => new \Zend_Db_Expr("NOW()")
            ),
            $this->USER_NAME_COLUMN.' = '.$this->db->quoteInto('?',$username));
    }

    /**
     * Fetches the user's salt from the database.
     * @param $username The user whose salt should be fetched.
     * @return String|null Returns the salt-string or Null, when the user does not exist.
     */
    private function getUserSalt($username){
        $this->db->getConnection();
        $res = $this->db->select()
            ->from($this->userTable,$this->SALT_COLUMN)
            ->where($this->USER_NAME_COLUMN.' = ?',$username)
            ->query()->fetch();
        return $res[$this->SALT_COLUMN];
    }

    /**
     * Fetches the user information from the database.
     * @param $username The name of the user.
     * @return User|null Returns the user object, or null when the user does not exist.
     */
    private function getUserByName($username){
        $this->db->getConnection();
        $res = $this->db->
            select()->from($this->userTable)
                ->where($this->USER_NAME_COLUMN.' = ?',$username)
                ->where($this->ACTIVE_COLUMN.' = ?',true)
                ->query()->fetch();
        if(empty($res)){
            return null;
        }
        return $this->createUserFromResult($res);
    }

    /**
     * Creates a new instance of User from the given result-array.
     * @param array $result The query result-array containing the column
     * @return User The created instance of User.
     */
    private function createUserFromResult(Array $result){
        $usr = new User(
            $result[$this->USER_NAME_COLUMN],
            $result[$this->FIRST_NAME_COLUMN],
            $result[$this->LAST_NAME_COLUMN],
            $result[$this->EMAIL_COLUMN]);
        $usr->setDomain($result[$this->DOMAIN_COLUMN]);
        return $usr;
    }
}