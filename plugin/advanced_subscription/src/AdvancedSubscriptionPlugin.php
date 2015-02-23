<?php
/* For licensing terms, see /license.txt */
/**
 * This class is used to add an advanced subscription allowing the admin to
 * create user queues requesting a subscribe to a session
 * @package chamilo.plugin.advanced_subscription
 */


class AdvancedSubscriptionPlugin extends Plugin implements HookPluginInterface
{
    protected $strings;
    /**
     * Constructor
     */
    function __construct()
    {
        $parameters = array(
            'yearly_cost_limit' => 'text',
            'yearly_hours_limit' => 'text',
            'yearly_cost_unit_converter' => 'text',
            'courses_count_limit' => 'text',
            'course_session_credit_year_start_date' => 'text',
            'ws_url' => 'text',
            'min_profile_percentage' => 'text',
            'check_induction' => 'boolean',
            'secret_key' => 'text',
        );

        parent::__construct('1.0', 'Imanol Losada, Daniel Barreto', $parameters);
    }

    /**
     * Instance the plugin
     * @staticvar null $result
     * @return AdvancedSubscriptionPlugin
     */
    static function create()
    {
        static $result = null;

        return $result ? $result : $result = new self();
    }

    /**
     * Install the plugin
     * @return void
     */
    public function install()
    {
        $this->installDatabase();
        $this->installHook();
    }

    /**
     * Uninstall the plugin
     * @return void
     */
    public function uninstall()
    {
        $this->uninstallHook();
        $this->uninstallDatabase();
    }

    /**
     * Create the database tables for the plugin
     * @return void
     */
    private function installDatabase()
    {
        $advancedSubscriptionQueueTable = Database::get_main_table(TABLE_ADVANCED_SUBSCRIPTION_QUEUE);

        $sql = "CREATE TABLE IF NOT EXISTS $advancedSubscriptionQueueTable (" .
            "id int UNSIGNED NOT NULL AUTO_INCREMENT, " .
            "session_id int UNSIGNED NOT NULL, " .
            "user_id int UNSIGNED NOT NULL, " .
            "status int UNSIGNED NOT NULL, " .
            "last_message_id int UNSIGNED NOT NULL, " .
            "created_at datetime NOT NULL, " .
            "updated_at datetime NULL, " .
            "PRIMARY KEY PK_advanced_subscription_queue (id), " .
            "UNIQUE KEY UK_advanced_subscription_queue (user_id, session_id)); ";
        Database::query($sql);
    }

    /**
     * Drop the database tables for the plugin
     * @return void
     */
    private function uninstallDatabase()
    {
        /* Drop plugin tables */
        $advancedSubscriptionQueueTable = Database::get_main_table(TABLE_ADVANCED_SUBSCRIPTION_QUEUE);

        $sql = "DROP TABLE IF EXISTS $advancedSubscriptionQueueTable; ";
        Database::query($sql);

        /* Delete settings */
        $settingsTable = Database::get_main_table(TABLE_MAIN_SETTINGS_CURRENT);
        Database::query("DELETE FROM $settingsTable WHERE subkey = 'advanced_subscription'");
    }

    /**
     * Return true if user is allowed to be added to queue for session subscription
     * @param int $userId
     * @param array $params MUST have keys:
     * "is_connected" Indicate if the user is online on external web
     * "profile_completed" Percentage of completed profile, given by WS
     * @throws Exception
     * @return bool
     */
    public function isAllowedToDoRequest($userId, $params = array())
    {
        if (isset($params['is_connected']) && isset($params['profile_completed'])) {
            $isAllowed = false;
            $plugin = self::create();
            $wsUrl = $plugin->get('ws_url');
            // @TODO: Get connection status from user by WS
            $isConnected = $params['is_connected'];
            if ($isConnected) {
                $profileCompletedMin = (float) $plugin->get('min_profile_percentage');
                // @TODO: Get completed profile percentage by WS
                $profileCompleted = (float) $params['profile_completed'];
                if ($profileCompleted > $profileCompletedMin) {
                    $checkInduction = $plugin->get('check_induction');
                    // @TODO: check if user have completed at least one induction session
                    $completedInduction = true;
                    if (!$checkInduction || $completedInduction) {
                        $uitMax = $plugin->get('yearly_cost_unit_converter');
                        $uitMax *= $plugin->get('yearly_cost_limit');
                        // @TODO: Get UIT completed by user this year by WS
                        $uitUser = 0;
                        $extra = new ExtraFieldValue('session');
                        $var = $extra->get_values_by_handler_and_field_variable($params['session_id'], 'cost');
                        $uitUser += $var['field_value'];
                        if ($uitMax >= $uitUser) {
                            $expendedTimeMax = $plugin->get('yearly_hours_limit');
                            // @TODO: Get Expended time from user data
                            $expendedTime = 0;
                            $var = $extra->get_values_by_handler_and_field_variable($params['session_id'], 'duration');
                            $expendedTime += $var['field_value'];
                            if ($expendedTimeMax >= $expendedTime) {
                                $expendedNumMax = $plugin->get('courses_count_limit');
                                // @TODO: Get Expended num from user
                                $expendedNum = 0;
                                if ($expendedNumMax >= $expendedNum) {
                                    $isAllowed = true;
                                } else {
                                    throw new \Exception($this->get_lang('AdvancedSubscriptionCourseXLimitReached'));
                                }
                            } else {
                                throw new \Exception($this->get_lang('AdvancedSubscriptionTimeXLimitReached'));
                            }
                        } else {
                            throw new \Exception($this->get_lang('AdvancedSubscriptionCostXLimitReached'));
                        }
                    } else {
                        throw new \Exception($this->get_lang('AdvancedSubscriptionIncompleteInduction'));
                    }
                } else {
                    throw new \Exception($this->get_lang('AdvancedSubscriptionProfileIncomplete'));
                }
            } else {
                throw new \Exception($this->get_lang('AdvancedSubscriptionNotConnected'));
            }

            return $isAllowed;
        } else {
            throw new \Exception($this->get_lang('AdvancedSubscriptionIncompleteParams'));
        }

    }

    /**
     * Register a user into a queue for a session
     * @param $userId
     * @param $sessionId
     * @return bool|int
     */
    public function addToQueue($userId, $sessionId)
    {
        // Filter input variables
        $userId = intval($userId);
        $sessionId = intval($sessionId);
        $now = api_get_utc_datetime();
        $advancedSubscriptionQueueTable = Database::get_main_table(TABLE_ADVANCED_SUBSCRIPTION_QUEUE);
        $attributes = array(
            'session_id' => $sessionId,
            'user_id' => $userId,
            'status' => 0,
            'created_at' => $now,
            'updated_at' => null,
        );

        $id = Database::insert($advancedSubscriptionQueueTable, $attributes);

        return $id;
    }

    /**
     * Register message with type and status
     * @param $mailId
     * @param $userId
     * @param $sessionId
     * @return bool|int
     */
    public function saveLastMessage($mailId, $userId, $sessionId)
    {
        // Filter variables
        $mailId = intval($mailId);
        $userId = intval($userId);
        $sessionId = intval($sessionId);
        $queueTable = Database::get_main_table(TABLE_ADVANCED_SUBSCRIPTION_QUEUE);
        $attributes = array(
            'last_message_id' => $mailId,
        );

        $num = Database::update(
            $queueTable,
            $attributes,
            array('user_id = ? AND session_id = ?' => array($userId, $sessionId))
        );

        return $num;
    }

    /**
     * Check for requirements and register user into queue
     * @param $userId
     * @param $sessionId
     * @param $params
     * @return bool|string
     */
    public function startSubscription($userId, $sessionId, $params)
    {
        $result = false;
        if (!empty($sessionId) && !empty($userId)) {
            $plugin = self::create();
            try {
                if ($plugin->isAllowedToDoRequest($userId, $params)) {
                    $result = (bool) $plugin->addToQueue($userId, $sessionId);
                } else {
                    throw new \Exception($this->get_lang('AdvancedSubscriptionNotMoreAble'));
                }
            } catch (Exception $e) {
                $result = $e->getMessage();
            }
        } else {
            $result = 'Params not found';
        }

        return $result;
    }

    /**
     * Send message for the student subscription approval to a specific session
     * @param int $studentId
     * @param int $receiverId
     * @param string $subject
     * @param string $content
     * @param int $sessionId
     * @param bool $save
     * @return bool|int
     */
    public function sendMailMessage($studentId, $receiverId, $subject, $content, $sessionId, $save = false)
    {
        $mailId = MessageManager::send_message(
            $receiverId,
            $subject,
            $content
        );

        if ($save && !empty($mailId)) {
            // Save as sent message
            $this->saveLastMessage($mailId, $studentId, $sessionId);
        }
        return $mailId;
    }

    /**
     * Check if session is open for subscription
     * @param $sessionId
     * @param string $fieldVariable
     * @return bool
     */
    public function isSessionOpen($sessionId, $fieldVariable = 'is_open_session')
    {
        $sessionId = (int) $sessionId;
        $fieldVariable = Database::escape_string($fieldVariable);
        $isOpen = false;
        if ($sessionId > 0 && !empty($fieldVariable)) {
            $sfTable = Database::get_main_table(TABLE_MAIN_SESSION_FIELD);
            $sfvTable = Database::get_main_table(TABLE_MAIN_SESSION_FIELD_VALUES);
            $joinTable = $sfvTable . ' sfv INNER JOIN ' . $sfTable . ' sf ON sfv.field_id = sf.id ';
            $row = Database::select(
                'sfv.field_value as field_value',
                $joinTable,
                array(
                    'where' => array(
                        'sfv.session_id = ? AND sf.field_variable = ?' => array($sessionId, $fieldVariable),
                    )
                )
            );
            if (isset($row[0]) && is_array($row[0])) {
                $isOpen = (bool) $row[0]['field_value'];
            }
        }

        return $isOpen;
    }

    /**
     * Update the queue status for subscription approval rejected or accepted
     * @param $params
     * @param $newStatus
     * @return bool
     */
    public function updateQueueStatus($params, $newStatus)
    {
        $newStatus = intval($newStatus);
        if (isset($params['queue']['id'])) {
            $where = array(
                'id = ?' => intval($params['queue']['id']),
            );
        } elseif(isset($params['studentUserId']) && isset($params['sessionId'])) {
            $where = array(
                'user_id = ? AND session_id = ? AND status <> ? AND status <> ?' => array(
                    intval($params['studentUserId']),
                    intval($params['sessionId']),
                    $newStatus,
                    ADVANCED_SUBSCRIPTION_QUEUE_STATUS_ADMIN_APPROVED,
                ),
            );
        }
        if (isset($where)) {
            $res = (bool) Database::update(
                Database::get_main_table(TABLE_ADVANCED_SUBSCRIPTION_QUEUE),
                array(
                    'status' => $newStatus,
                    'updated_at' => api_get_utc_datetime(),
                ),
                $where
            );
        } else {
            $res = false;
        }

        return $res;
    }

    /**
     * Render and send mail by defined advanced subscription action
     * @param $data
     * @param $actionType
     * @return array
     */
    public function sendMail($data, $actionType)
    {
        $template = new Template($this->get_lang('plugin_title'));
        $template->assign('data', $data);
        $templateParams = array(
            'user',
            'student',
            'students',
            'superior',
            'admins',
            'session',
            'signature',
            'admin_view_url',
            'acceptUrl',
            'rejectUrl'
        );
        foreach ($templateParams as $templateParam) {
            $template->assign($templateParam, $data[$templateParam]);
        }
        $mailIds = array();
        switch ($actionType) {
            case ADVANCED_SUBSCRIPTION_ACTION_STUDENT_REQUEST:
                // Mail to student
                $mailIds['render'] = $this->sendMailMessage(
                    $data['studentUserId'],
                    $data['student']['user_id'],
                    $this->get_lang('MailStudentRequest'),
                    $template->fetch('/advanced_subscription/views/student_notice_student.tpl'),
                    $data['sessionId']
                );
                // Mail to superior
                $mailIds[] = $this->sendMailMessage(
                    $data['studentUserId'],
                    $data['superior']['user_id'],
                    $this->get_lang('MailStudentRequest'),
                    $template->fetch('/advanced_subscription/views/student_notice_superior.tpl'),
                    $data['sessionId'],
                    true
                );
                break;
            case ADVANCED_SUBSCRIPTION_ACTION_SUPERIOR_APPROVE:
                // Mail to student
                $mailIds[] = $this->sendMailMessage(
                    $data['studentUserId'],
                    $data['student']['user_id'],
                    $this->get_lang('MailBossAccept'),
                    $template->fetch('/advanced_subscription/views/superior_accepted_notice_student.tpl'),
                    $data['sessionId']
                );
                // Mail to superior
                $mailIds['render'] = $this->sendMailMessage(
                    $data['studentUserId'],
                    $data['superior']['user_id'],
                    $this->get_lang('MailBossAccept'),
                    $template->fetch('/advanced_subscription/views/superior_accepted_notice_superior.tpl'),
                    $data['sessionId']
                );
                // Mail to admin
                foreach ($data['admins'] as $adminId => $admin) {
                    $template->assign('admin', $admin);
                    $mailIds[] = $this->sendMailMessage(
                        $data['studentUserId'],
                        $adminId,
                        $this->get_lang('MailBossAccept'),
                        $template->fetch('/advanced_subscription/views/superior_accepted_notice_admin.tpl'),
                        $data['sessionId'],
                        true
                    );
                }
                break;
            case ADVANCED_SUBSCRIPTION_ACTION_SUPERIOR_DISAPPROVE:
                // Mail to student
                $mailIds[] = $this->sendMailMessage(
                    $data['studentUserId'],
                    $data['student']['user_id'],
                    $this->get_lang('MailBossReject'),
                    $template->fetch('/advanced_subscription/views/superior_rejected_notice_student.tpl'),
                    $data['sessionId'],
                    true
                );
                // Mail to superior
                $mailIds['render'] = $this->sendMailMessage(
                    $data['studentUserId'],
                    $data['superior']['user_id'],
                    $this->get_lang('MailBossReject'),
                    $template->fetch('/advanced_subscription/views/superior_rejected_notice_superior.tpl'),
                    $data['sessionId']
                );
                break;
            case ADVANCED_SUBSCRIPTION_ACTION_SUPERIOR_SELECT:
                // Mail to student
                $mailIds[] = $this->sendMailMessage(
                    $data['studentUserId'],
                    $data['student']['user_id'],
                    $this->get_lang('MailStudentRequestSelect'),
                    $template->fetch('/advanced_subscription/views/student_notice_student.tpl'),
                    $data['sessionId']
                );
                // Mail to superior
                $mailIds['render'] = $this->sendMailMessage(
                    $data['studentUserId'],
                    $data['superior']['user_id'],
                    $this->get_lang('MailStudentRequestSelect'),
                    $template->fetch('/advanced_subscription/views/student_notice_superior.tpl'),
                    $data['sessionId'],
                    true
                );
                break;
            case ADVANCED_SUBSCRIPTION_ACTION_ADMIN_APPROVE:
                // Mail to student
                $mailIds[] = $this->sendMailMessage(
                    $data['studentUserId'],
                    $data['student']['user_id'],
                    $this->get_lang('MailAdminAccept'),
                    $template->fetch('/advanced_subscription/views/admin_accepted_notice_student.tpl'),
                    $data['sessionId']
                );
                // Mail to superior
                $mailIds[] = $this->sendMailMessage(
                    $data['studentUserId'],
                    $data['superior']['user_id'],
                    $this->get_lang('MailAdminAccept'),
                    $template->fetch('/advanced_subscription/views/admin_accepted_notice_superior.tpl'),
                    $data['sessionId']
                );
                // Mail to admin
                $adminId = $data['currentUserId'];
                $template->assign('admin', $data['admins'][$adminId]);
                $mailIds['render'] = $this->sendMailMessage(
                    $data['studentUserId'],
                    $adminId,
                    $this->get_lang('MailAdminAccept'),
                    $template->fetch('/advanced_subscription/views/admin_accepted_notice_admin.tpl'),
                    $data['sessionId'],
                    true
                );
                break;
            case ADVANCED_SUBSCRIPTION_ACTION_ADMIN_DISAPPROVE:
                // Mail to student
                $mailIds[] = $this->sendMailMessage(
                    $data['studentUserId'],
                    $data['student']['user_id'],
                    $this->get_lang('MailAdminReject'),
                    $template->fetch('/advanced_subscription/views/admin_rejected_notice_student.tpl'),
                    $data['sessionId'],
                    true
                );
                // Mail to superior
                $mailIds[] = $this->sendMailMessage(
                    $data['studentUserId'],
                    $data['superior']['user_id'],
                    $this->get_lang('MailAdminReject'),
                    $template->fetch('/advanced_subscription/views/admin_rejected_notice_superior.tpl'),
                    $data['sessionId']
                );
                // Mail to admin
                $adminId = $data['currentUserId'];
                $template->assign('admin', $data['admins'][$adminId]);
                $mailIds['render'] = $this->sendMailMessage(
                    $data['studentUserId'],
                    $adminId,
                    $this->get_lang('MailAdminReject'),
                    $template->fetch('/advanced_subscription/views/admin_rejected_notice_admin.tpl'),
                    $data['sessionId']
                );
                break;
            case ADVANCED_SUBSCRIPTION_ACTION_STUDENT_REQUEST_NO_BOSS:
                // Mail to student
                $mailIds['render'] = $this->sendMailMessage(
                    $data['studentUserId'],
                    $data['student']['user_id'],
                    $this->get_lang('MailStudentRequestNoBoss'),
                    $template->fetch('/advanced_subscription/views/student_no_superior_notice_student.tpl'),
                    $data['sessionId']
                );
                // Mail to admin
                foreach ($data['admins'] as $adminId => $admin) {
                    $template->assign('admin', $admin);
                    $mailIds[] = $this->sendMailMessage(
                        $data['studentUserId'],
                        $adminId,
                        $this->get_lang('MailStudentRequestNoBoss'),
                        $template->fetch('/advanced_subscription/views/student_no_superior_notice_admin.tpl'),
                        $data['sessionId'],
                        true
                    );
                }
                break;
            default:
                break;
        }

        return $mailIds;
    }

    /**
     * Count the users in queue filtered by params (sessions, status)
     * @param array $params Input array containing the set of
     * session and status to count from queue
     * e.g:
     * array('sessions' => array(215, 218, 345, 502),
     * 'status' => array(0, 1, 2))
     * @return int
     */
    public function countQueueByParams($params)
    {
        $count = 0;
        if (!empty($params) && is_array($params)) {
            $advancedSubscriptionQueueTable = Database::get_main_table(TABLE_ADVANCED_SUBSCRIPTION_QUEUE);
            $where['1 = ? '] = 1;
            if (isset($params['sessions']) && is_array($params['sessions'])) {
                foreach ($params['sessions'] as &$sessionId) {
                    $sessionId = intval($sessionId);
                }
                $where['AND session_id IN ( ? ) '] = implode($params['sessions']);
            }
            if (isset($params['status']) && is_array($params['status'])) {
                foreach ($params['status'] as &$status) {
                    $status = intval($status);
                }
                $where['AND status IN ( ? ) '] = implode($params['status']);
            }
            $where['where'] = $where;
            $count = Database::select('COUNT(*)', $advancedSubscriptionQueueTable, $where);
            $count = $count[0]['COUNT(*)'];
        }
        return $count;
    }

    /**
     * This method will call the Hook management insertHook to add Hook observer from this plugin
     * @return int
     */
    public function installHook()
    {
        $hookObserver = HookAdvancedSubscription::create();
        HookAdminBlock::create()->attach($hookObserver);
        HookWSRegistration::create()->attach($hookObserver);
        HookNotificationContent::create()->attach($hookObserver);
        HookNotificationTitle::create()->attach($hookObserver);
    }

    /**
     * This method will call the Hook management deleteHook to disable Hook observer from this plugin
     * @return int
     */
    public function uninstallHook()
    {
        $hookObserver = HookAdvancedSubscription::create();
        HookAdminBlock::create()->detach($hookObserver);
        HookWSRegistration::create()->detach($hookObserver);
        HookNotificationContent::create()->detach($hookObserver);
        HookNotificationTitle::create()->detach($hookObserver);
    }

    /**
     * Return the status from user in queue to session subscription
     * @param int $userId
     * @param int $sessionId
     * @return bool|int
     */
    public function getQueueStatus($userId, $sessionId)
    {
        $userId = intval($userId);
        $sessionId = intval($sessionId);
        if (!empty($userId) && !empty($sessionId)) {
            $queueTable = Database::get_main_table(TABLE_ADVANCED_SUBSCRIPTION_QUEUE);
            $row = Database::select(
                'status',
                $queueTable,
                array(
                    'where' => array(
                        'user_id = ? AND session_id = ?' => array($userId, $sessionId),
                    )
                )
            );

            if (count($row) == 1) {

                return $row[0]['status'];
            } else {

                return ADVANCED_SUBSCRIPTION_QUEUE_STATUS_NO_QUEUE;
            }
        }

        return false;
    }

    /**
     * Return the remaining vacancy
     * @param $sessionId
     * @return bool|int
     */
    public function getVacancy($sessionId)
    {
        if (!empty($sessionId)) {
            $extra = new ExtraFieldValue('session');
            $var = $extra->get_values_by_handler_and_field_variable($sessionId, 'vacancies');
            $vacancy = intval($var['field_value']);
            if (!empty($vacancy)) {
                $vacancy -= $this->countQueueByParams(
                    array(
                        'sessions' => array($sessionId),
                        'status' => array(ADVANCED_SUBSCRIPTION_QUEUE_STATUS_ADMIN_APPROVED),
                    )
                );
                if ($vacancy >= 0) {

                    return $vacancy;
                }
            }
        }

        return false;
    }

    /**
     * Return the session details data from a session ID (including the extra
     * fields used for the advanced subscription mechanism)
     * @param $sessionId
     * @return bool|mixed
     */
    public function getSessionDetails($sessionId)
    {
        if (!empty($sessionId)) {
            // Assign variables
            $fieldsArray = array('id', 'cost', 'place', 'allow_visitors', 'teaching_hours', 'brochure', 'banner');
            $extraSession = new ExtraFieldValue('session');
            $extraField = new ExtraField('session');
            // Get session fields
            $fieldList = $extraField->get_all(array(
                'field_variable IN ( ?, ?, ?, ?, ?)' => $fieldsArray
            ));
            // Index session fields
            foreach ($fieldList as $field) {
                $fields[$field['id']] = $field['field_variable'];
            }

            $mergedArray = array_merge(array($sessionId), array_keys($fields));
            $sessionFieldValueList = $extraSession->get_all(array('session_id = ? field_id IN ( ?, ?, ?, ?, ?, ?, ? )' => $mergedArray));
            foreach ($sessionFieldValueList as $sessionFieldValue) {
                // Check if session field value is set in session field list
                if (isset($fields[$sessionFieldValue['field_id']])) {
                    $var = $fields[$sessionFieldValue['field_id']];
                    $val = $sessionFieldValue['field_value'];
                    // Assign session field value to session
                    $sessionArray[$var] = $val;
                }
            }
            $sessionArray['description'] = SessionManager::getDescriptionFromSessionId($sessionId);

            return $sessionArray;
        }

        return false;
    }

    /**
     * Get status message
     * @param int $status
     * @param bool $isAble
     * @return string
     */
    public function getStatusMessage($status, $isAble = true)
    {
        $message = '';
        switch ($status)
        {
            case ADVANCED_SUBSCRIPTION_QUEUE_STATUS_NO_QUEUE:
                if ($isAble) {
                    $message = $this->get_lang('AdvancedSubscriptionNoQueueIsAble');
                } else {
                    $message = $this->get_lang('AdvancedSubscriptionNoQueue');
                }
                break;
            case ADVANCED_SUBSCRIPTION_QUEUE_STATUS_START:
                $message = $this->get_lang('AdvancedSubscriptionQueueStart');
                break;
            case ADVANCED_SUBSCRIPTION_QUEUE_STATUS_BOSS_DISAPPROVED:
                $message = $this->get_lang('AdvancedSubscriptionQueueBossDisapproved');
                break;
            case ADVANCED_SUBSCRIPTION_QUEUE_STATUS_BOSS_APPROVED:
                $message = $this->get_lang('AdvancedSubscriptionQueueBossApproved');
                break;
            case ADVANCED_SUBSCRIPTION_QUEUE_STATUS_ADMIN_DISAPPROVED:
                $message = $this->get_lang('AdvancedSubscriptionQueueAdminDisapproved');
                break;
            case ADVANCED_SUBSCRIPTION_QUEUE_STATUS_ADMIN_APPROVED:
                $message = $this->get_lang('AdvancedSubscriptionQueueAdminApproved');
                break;
            default:
                $message = sprintf($this->get_lang('AdvancedSubscriptionQueueDefault'), $status);

        }

        return $message;
    }

    /**
     * Return the url to go to session
     * @param $sessionId
     * @return string
     */
    public function getSessionUrl($sessionId)
    {
        $url = api_get_path(WEB_CODE_PATH) . 'session/?session_id=' . intval($sessionId);
        return $url;
    }

    /**
     * Return the url to enter to subscription queue to session
     * @param $params
     * @return string
     */
    public function getQueueUrl($params)
    {
        $url = api_get_path(WEB_PLUGIN_PATH) . 'advanced_subscription/ajax/advanced_subscription.ajax.php?' .
            'a=' . Security::remove_XSS($params['action']) . '&' .
            's=' . intval($params['sessionId']) . '&' .
            'current_user_id=' . intval($params['currentUserId']) . '&' .
            'e=' . intval($params['newStatus']) . '&' .
            'u=' . intval($params['studentUserId']) . '&' .
            'q=' . intval($params['queueId']) . '&' .
            'is_connected=' . intval($params['is_connected']) . '&' .
            'profile_completed=' . intval($params['profile_completed']) . '&' .
            'v=' . $this->generateHash($params);
        return $url;
    }

    /**
     * Return the list of student, in queue used by admin view
     * @param int $sessionId
     * @return array
     */
    public function listAllStudentsInQueueBySession($sessionId)
    {
        // Filter input variable
        $sessionId = intval($sessionId);
        // Assign variables
        $fieldsArray = array('target', 'publication_end_date', 'mode', 'recommended_number_of_participants', 'vacancies');
        $sessionArray = api_get_session_info($sessionId);
        $extraSession = new ExtraFieldValue('session');
        $extraField = new ExtraField('session');
        // Get session fields
        $fieldList = $extraField->get_all(array(
            'field_variable IN ( ?, ?, ?, ?, ?)' => $fieldsArray
        ));
        // Index session fields
        foreach ($fieldList as $field) {
            $fields[$field['id']] = $field['field_variable'];
        }

        $mergedArray = array_merge(array($sessionId), array_keys($fields));
        $sessionFieldValueList = $extraSession->get_all(array('session_id = ? field_id IN ( ?, ?, ?, ?, ?, ?, ? )' => $mergedArray));
        foreach ($sessionFieldValueList as $sessionFieldValue) {
            // Check if session field value is set in session field list
            if (isset($fields[$sessionFieldValue['field_id']])) {
                $var = $fields[$sessionFieldValue['field_id']];
                $val = $sessionFieldValue['field_value'];
                // Assign session field value to session
                $sessionArray[$var] = $val;
            }
        }
        $queueTable = Database::get_main_table(TABLE_ADVANCED_SUBSCRIPTION_QUEUE);
        $userTable = Database::get_main_table(TABLE_MAIN_USER);
        $userJoinTable = $queueTable . ' q INNER JOIN ' . $userTable . ' u ON q.user_id = u.user_id';
        $where = array(
            'where' =>
            array(
                'q.session_id = ? AND q.status <> ? AND q.status <> ?' => array(
                    $sessionId,
                    ADVANCED_SUBSCRIPTION_QUEUE_STATUS_ADMIN_APPROVED,
                    ADVANCED_SUBSCRIPTION_QUEUE_STATUS_ADMIN_DISAPPROVED,
                )
            )
        );
        $select = 'u.user_id, u.firstname, u.lastname, q.created_at, q.updated_at, q.status, q.id as queue_id';
        $students = Database::select($select, $userJoinTable, $where);
        foreach ($students as &$student) {
            $status = intval($student['status']);
            switch($status) {
                case ADVANCED_SUBSCRIPTION_QUEUE_STATUS_NO_QUEUE:
                case ADVANCED_SUBSCRIPTION_QUEUE_STATUS_START:
                    $student['validation'] = '';
                    break;
                case ADVANCED_SUBSCRIPTION_QUEUE_STATUS_BOSS_DISAPPROVED:
                case ADVANCED_SUBSCRIPTION_QUEUE_STATUS_ADMIN_DISAPPROVED:
                    $student['validation'] = get_lang('No');
                    break;
                case ADVANCED_SUBSCRIPTION_QUEUE_STATUS_BOSS_APPROVED:
                case ADVANCED_SUBSCRIPTION_QUEUE_STATUS_ADMIN_APPROVED:
                    $student['validation'] = get_lang('Yes');
                    break;
                default:
                    error_log(__FILE__ . ' ' . __FUNCTION__ . ' Student status no detected');
            }
        }
        $return = array(
            'session' => $sessionArray,
            'students' => $students,
        );

        return $return;

    }

    /**
     * List all session (id, name) for select input
     * @param int $limit
     * @return array
     */
    public function listAllSessions($limit = 100)
    {
        $limit = intval($limit);
        $sessionTable = Database::get_main_table(TABLE_MAIN_SESSION);
        $columns = 'id, name';
        $conditions = array();
        if ($limit > 0) {
            $conditions = array(
                'order' => 'name',
                'limit' => $limit,
            );
        }

        return Database::select($columns, $sessionTable, $conditions);
    }

    /**
     * Generate security hash to check data send by url params
     * @param string $data
     * @return string
     */
    public function generateHash($data)
    {
        $key = sha1($this->get('secret_key'));
        // Prepare array to have specific type variables
        $dataPrepared['action'] = strval($data['action']);
        $dataPrepared['sessionId'] = intval($data['sessionId']);
        $dataPrepared['currentUserId'] = intval($data['currentUserId']);
        $dataPrepared['studentUserId'] = intval($data['studentUserId']);
        $dataPrepared['queueId'] = intval($data['queueId']);
        $dataPrepared['newStatus'] = intval($data['newStatus']);
        $dataPrepared = serialize($dataPrepared);
        return sha1($dataPrepared . $key);
    }

    /**
     * Verify hash from data
     * @param string $data
     * @param string $hash
     * @return bool
     */
    public function checkHash($data, $hash)
    {
        return $this->generateHash($data) == $hash;
    }

    /**
     * Copied and fixed from plugin.class.php
     * Returns the "system" name of the plugin in lowercase letters
     * @return string
     */
    public function get_name()
    {
        return 'advanced_subscription';
    }
}
