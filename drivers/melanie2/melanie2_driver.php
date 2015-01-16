<?php
/**
 * Melanie2 driver for the Calendar plugin
 *
 * @version @package_version@
 *
 * @author PNE Annuaire et Messagerie/MEDDE
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */
// Configuration du nom de l'application pour l'ORM
if (!defined('CONFIGURATION_APP_LIBM2')) {
    define('CONFIGURATION_APP_LIBM2', 'roundcube');
}
// Inclusion de l'ORM
require_once 'includes/libm2.php';

require_once(dirname(__FILE__) . '/melanie2_mapping.php');

/**
 * Classe Melanie2 Driver
 * Permet de gérer les calendriers Melanie2 depuis Roundcube
 * @author Thomas Payen <thomas.payen@i-carre.net> PNE Annuaire et Messagerie/MEDDE
 */
class melanie2_driver extends calendar_driver
{
    const DB_DATE_FORMAT = 'Y-m-d H:i:s';
    const SHORT_DB_DATE_FORMAT = 'Y-m-d';
    const RECURRENCE_ID = '@RECURRENCE-ID';
    const RECURRENCE_DATE = '-XXXXXXXX';

    // features this backend supports
    public $alarms = true;
    public $attendees = true;
    public $freebusy = true;
    public $attachments = true;
    public $undelete = false;
    public $alarm_types = array('DISPLAY');
    public $categoriesimmutable = false;

    private $rc;
    private $cal;
    /**
     * Tableau de calendrier Melanie2
     * @var LibMelanie\Api\Melanie2\Calendar []
     */
    private $calendars;
    private $has_principal = false;
    private $freebusy_trigger = false;

    // Melanie2
    /**
     * Utilisateur Melanie2
     * @var LibMelanie\Api\Melanie2\User
     */
    private $user;
    /**
     * Mise en cache des évènements
     * Pour éviter d'aller les chercher plusieurs fois dans la base de données
     * @var array
     */
    private $_cache_events = array();

    /**
     * Default constructor
     */
    public function __construct($cal)
    {
        melanie2_logs::get_instance()->log(melanie2_logs::DEBUG, "[calendar] melanie2_driver::__construct()");
        $this->cal = $cal;
        $this->rc = $cal->rc;
        $this->server_timezone = new DateTimeZone(date_default_timezone_get());

        // User Melanie2
        if (!empty($this->rc->user->ID)) {
            $this->user = new LibMelanie\Api\Melanie2\User();
            $this->user->uid = $this->rc->user->get_username();
        }
        // Charge les données seulement si on est dans la tâche calendrier
        if ($this->rc->task == 'calendar') {
            $this->cal->register_action('calendar-acl', array($this, 'calendar_acl'));
            $this->cal->register_action('calendar-acl-group', array($this, 'calendar_acl_group'));

            // load library classes
            require_once($this->cal->home . '/lib/Horde_Date_Recurrence.php');
        }
    }

    /**** METHODS PRIVATES *****/
    /**
     * Read available calendars for the current user and store them internally
     * @access private
     */
    private function _read_calendars()
    {
        melanie2_logs::get_instance()->log(melanie2_logs::DEBUG, "[calendar] melanie2_driver::_read_calendars()");
        if (isset($this->user)) {
            $this->calendars = $this->user->getSharedCalendars();
            //$this->calendars = $this->user->getUserCalendars();
            foreach ($this->calendars as $calendar) {
                if (!$this->has_principal
                        && $calendar->id == $this->user->uid) {
                    $this->has_principal = true;
                    break;
                }
            }
        }
    }

    /**
     * Génération d'un code couleur aléatoire
     * Utilisé pour générer une premiere couleur pour les agendas si aucune n'est positionnée
     * @return string Code couleur
     * @private
     */
    private function _random_color()
    {
        mt_srand((double)microtime()*1000000);
        $c = '';
        while(strlen($c)<6){
        	$c .= sprintf("%02X", mt_rand(0, 255));
        }
        return $c;
    }

    /**** METHODS PUBLICS *****/
    /**
     * Get a list of available calendars from this source
     *
     * @param bool $active   Return only active calendars
     * @param bool $personal Return only personal calendars
     *
     * @return array List of calendars
     */
    public function list_calendars($only_active = false, $only_personal = false)
    {
        if ($this->rc->task != 'calendar') {
            return;
        }
        melanie2_logs::get_instance()->log(melanie2_logs::DEBUG, "[calendar] melanie2_driver::list_calendars(only_active = $only_active, only_personal = $only_personal)");

        try {
            // Chargement des calendriers si besoin
            if (!isset($this->calendars)) {
                $this->_read_calendars();
            }

            // Récupération des préférences de l'utilisateur
            $hidden_calendars = $this->rc->config->get('hidden_calendars', array());
            $color_calendars = $this->rc->config->get('color_calendars', array());
            $active_calendars = $this->rc->config->get('active_calendars', null);
            $alarm_calendars = $this->rc->config->get('alarm_calendars', null);

            // attempt to create a default calendar for this user
            if (!$this->has_principal) {
                $infos = melanie2::get_user_infos($this->user->uid);
                if ($this->create_calendar(array('id' => $this->user->uid, 'name' => $infos['cn'][0], 'color' => $this->_random_color()))) {
                    // Création du default calendar
                    $pref = new LibMelanie\Api\Melanie2\UserPrefs($this->user);
                    $pref->scope = LibMelanie\Config\ConfigMelanie::CALENDAR_PREF_SCOPE;
                    $pref->name = LibMelanie\Config\ConfigMelanie::CALENDAR_PREF_DEFAULT_NAME;
                    $pref->value = $this->user->uid;
                    $pref->save();
                    unset($pref);
                    // Création du display_cals (utile pour que pacome fonctionne)
                    $pref = new LibMelanie\Api\Melanie2\UserPrefs($this->user);
                    $pref->scope = LibMelanie\Config\ConfigMelanie::CALENDAR_PREF_SCOPE;
                    $pref->name = 'display_cals';
                    $pref->value = 'a:0:{}';
                    $pref->save();
                    unset($this->calendars);
                    $this->_read_calendars();
                }
            }
            try {
                $default_calendar = $this->user->getDefaultCalendar();
            }
            catch (Exception $ex) {
                // Si la récupération du calendrier par défaut échoue
                // Certainement le cas de la restauration (horde_prefs non présente)
                $default_calendar = $this->user->uid;
            }
            $owner_calendars = array();
            $other_calendars = array();
            $shared_calendars = array();
            $save_color = false;
            foreach ($this->calendars as $id => $cal) {
                if (isset($hidden_calendars[$cal->id]))
                    continue;
                // Gestion des paramètres du calendrier
                if (isset($color_calendars[$cal->id])) {
                    $color = $color_calendars[$cal->id];
                }
                else {
                    $color = $this->_random_color();
                    $color_calendars[$cal->id] = $color;
                    $save_color = true;
                }
                // Gestion des calendriers actifs
                if (isset($active_calendars)
                        && is_array($active_calendars)) {
                    $active = isset($active_calendars[$cal->id]);
                }
                else {
                    $active = true;
                    $active_calendars[$cal->id] = 1;
                }
                // Gestion des alarmes dans les calendriers
                if (isset($alarm_calendars)
                        && is_array($alarm_calendars)) {
                    $alarm = isset($alarm_calendars[$cal->id]);
                }
                else {
                    $alarm = $cal->owner == $this->user->uid;
                    if ($alarm)
                        $alarm_calendars[$cal->id] = 1;
                }
                // Se limiter aux calendriers actifs
                // Se limiter aux calendriers perso
                if ($only_active && !$active
                        || $only_personal && $cal->owner != $this->user->uid)
                    continue;
                // formatte le calendrier pour le driver
                $calendar = array(
                    'id'       => $this->_to_RC_id($cal->id),
                    'name'     => $cal->owner == $this->user->uid ? $cal->name : "[".$cal->owner."] ".$cal->name,
                    'listname'     => $cal->owner == $this->user->uid ? $cal->name : "[".$cal->owner."] ".$cal->name,
                    'editname' => $cal->name,
                    'color'    => $color,
                    'readonly' => !$cal->asRight(LibMelanie\Config\ConfigMelanie::WRITE),
                    'showalarms' => $alarm ? 1 : 0,
                    'class_name' => trim(($cal->owner == $this->user->uid ? 'personnal' : 'other') . ' ' . ($default_calendar->id == $cal->id ? 'default' : '')),
                    'default'  => $default_calendar->id == $cal->id,
                    'active'   => $active,
                    'owner'    => $cal->owner,
                    'children' => false,  // TODO: determine if that folder indeed has child folders
                    'caldavurl' => '',
                );
                // Ajout le calendrier dans la liste correspondante
                if ($calendar['owner'] != $this->user->uid) {
                    $id = $this->_to_RC_id($id);
                    $shared_calendars[$id] = $calendar;
                }
                elseif ($this->user->uid == $cal->id) {
                    $id = $this->_to_RC_id($id);
                    $owner_calendars[$id] = $calendar;
                }
                else {
                    $id = $this->_to_RC_id($id);
                    $other_calendars[$id] = $calendar;
                }
            }
            // Tri des tableaux
            asort($owner_calendars);
            asort($other_calendars);
            asort($shared_calendars);

            $this->rc->user->save_prefs(array(
                'color_calendars' => $color_calendars,
                'active_calendars' => $active_calendars,
                'alarm_calendars' => $alarm_calendars,
            ));

            melanie2_logs::get_instance()->log(melanie2_logs::TRACE, "[calendar] melanie2_driver::list_calendars() : " . var_export($owner_calendars + $other_calendars + $shared_calendars, true));

            // Retourne la concaténation des agendas pour avoir une liste ordonnée
            return $owner_calendars + $other_calendars + $shared_calendars;
        }
        catch (LibMelanie\Exceptions\Melanie2DatabaseException $ex) {
            melanie2_logs::get_instance()->log(melanie2_logs::ERROR, "[calendar] melanie2_driver::list_calendars() Melanie2DatabaseException");
            return array();
        }
        catch (\Exception $ex) {
            return array();
        }
        return array();
    }

    /**
     * Create a new calendar assigned to the current user
     *
     * @param array Hash array with calendar properties
     *    name: Calendar name
     *   color: The color of the calendar
     * @return mixed ID of the calendar on success, False on error
     */
    public function create_calendar($prop)
    {
        // Charge les données seulement si on est dans la tâche calendrier
        if ($this->rc->task != 'calendar') {
            return;
        }
        melanie2_logs::get_instance()->log(melanie2_logs::DEBUG, "[calendar] melanie2_driver::create_calendar()");
        melanie2_logs::get_instance()->log(melanie2_logs::TRACE, "[calendar] melanie2_driver::create_calendar() : " . var_export($prop, true));

        try {
            $calendar = new LibMelanie\Api\Melanie2\Calendar($this->user);
            $calendar->name = $prop['name'];
            $calendar->id = isset($prop['id']) ? $this->_to_M2_id($prop['id']) : md5($prop['name'] . time() . $this->user->uid);
            $calendar->owner = $this->user->uid;
            if ($calendar->save()) {
                // Récupération des préférences de l'utilisateur
                $active_calendars = $this->rc->config->get('active_calendars', array());
                $color_calendars = $this->rc->config->get('color_calendars', array());
                $alarm_calendars = $this->rc->config->get('alarm_calendars', array());
                // Display cal
                $active_calendars[$calendar->id] = 1;
                // Color cal
                $color_calendars[$calendar->id] = $prop['color'];
                // Showalarm ?
                if ($prop['showalarms'] == 1) {
                    $alarm_calendars[$calendar->id] = 1;
                }
                $this->rc->user->save_prefs(array(
                    'color_calendars' => $color_calendars,
                    'active_calendars' => $active_calendars,
                    'alarm_calendars' => $alarm_calendars,
                ));

                // Return the calendar id
                return $calendar->id;
            }
        }
        catch (LibMelanie\Exceptions\Melanie2DatabaseException $ex) {
            melanie2_logs::get_instance()->log(melanie2_logs::ERROR, "[calendar] melanie2_driver::create_calendar() Melanie2DatabaseException");
            return false;
        }
        catch (\Exception $ex) {
            return false;
        }
        return false;
    }

    /**
     * Update properties of an existing calendar
     *
     * @see calendar_driver::edit_calendar()
     */
    public function edit_calendar($prop)
    {
        // Charge les données seulement si on est dans la tâche calendrier
        if ($this->rc->task != 'calendar') {
            return;
        }
        melanie2_logs::get_instance()->log(melanie2_logs::DEBUG, "[calendar] melanie2_driver::edit_calendar()");
        melanie2_logs::get_instance()->log(melanie2_logs::TRACE, "[calendar] melanie2_driver::edit_calendar() : " . var_export($prop, true));

        try {
            // Chargement des calendriers si besoin
            if (!isset($this->calendars)) {
                $this->_read_calendars();
            }
            if (isset($prop['id'])) {
                $id = $this->_to_M2_id($prop['id']);
                if (isset($this->calendars[$id])) {
                    $cal = $this->calendars[$id];
                    if (isset($prop['name'])
                            && $cal->owner == $this->user->uid
                            && $prop['name'] != ""
                            && $prop['name'] != $cal->name) {
                        $cal->name = $prop['name'];
                        $cal->save();
                    }
                    // Récupération des préférences de l'utilisateur
                    $color_calendars = $this->rc->config->get('color_calendars', array());
                    $alarm_calendars = $this->rc->config->get('alarm_calendars', array());
                    $param_change = false;
                    if (!isset($color_calendars[$cal->id])
                          		|| $color_calendars[$cal->id] != $prop['color']) {
                        $color_calendars[$cal->id] = $prop['color'];
                        $param_change = true;
                    }
                    if (!isset($alarm_calendars[$cal->id])
                          		&& $prop['showalarms'] == 1) {
                        $alarm_calendars[$cal->id] = 1;
                        $param_change = true;
                    } elseif (isset($alarm_calendars[$cal->id])
                          		&& $prop['showalarms'] == 0) {
                        unset($alarm_calendars[$cal->id]);
                        $param_change = true;
                    }
                    if ($param_change) {
                        $this->rc->user->save_prefs(array(
                            'color_calendars' => $color_calendars,
                            'alarm_calendars' => $alarm_calendars,
                        ));
                    }
                    return true;
                }
            }
        }
        catch (LibMelanie\Exceptions\Melanie2DatabaseException $ex) {
            melanie2_logs::get_instance()->log(melanie2_logs::ERROR, "[calendar] melanie2_driver::edit_calendar() Melanie2DatabaseException");
            return false;
        }
        catch (\Exception $ex) {
            return false;
        }
        return false;
    }

    /**
     * Set active/subscribed state of a calendar
     * Save a list of hidden calendars in user prefs
     *
     * @see calendar_driver::subscribe_calendar()
     */
    public function subscribe_calendar($prop)
    {
        // Charge les données seulement si on est dans la tâche calendrier
        if ($this->rc->task != 'calendar') {
            return;
        }
        $id = $this->_to_M2_id($prop['id']);
        melanie2_logs::get_instance()->log(melanie2_logs::DEBUG, "[calendar] melanie2_driver::subscribe_calendar($id)");
        melanie2_logs::get_instance()->log(melanie2_logs::TRACE, "[calendar] melanie2_driver::subscribe_calendar() : " . var_export($prop, true));
        // Récupération des préférences de l'utilisateur
    	$active_calendars = $this->rc->config->get('active_calendars', array());

        if (!$prop['active'])
          unset($active_calendars[$id]);
        else
          $active_calendars[$id] = 1;

        return $this->rc->user->save_prefs(array('active_calendars' => $active_calendars));
    }

    /**
     * Delete the given calendar with all its contents
     *
     * @see calendar_driver::remove_calendar()
     */
    public function remove_calendar($prop)
    {
        // Charge les données seulement si on est dans la tâche calendrier
        if ($this->rc->task != 'calendar') {
            return false;
        }
        $id = $this->_to_M2_id($prop['id']);
        melanie2_logs::get_instance()->log(melanie2_logs::DEBUG, "[calendar] melanie2_driver::remove_calendar($id)");
        melanie2_logs::get_instance()->log(melanie2_logs::TRACE, "[calendar] melanie2_driver::remove_calendar() : " . var_export($prop, true));

        try {
            // Chargement des calendriers si besoin
            if (!isset($this->calendars)) {
                $this->_read_calendars();
            }
            if (isset($id)
                    && isset($this->calendars[$id])
                    && $this->calendars[$id]->owner == $this->user->uid
                    && $this->calendars[$id]->id != $this->user->uid) {
                // Récupération des préférences de l'utilisateur
                $hidden_calendars = $this->rc->config->get('hidden_calendars', array());
                $active_calendars = $this->rc->config->get('active_calendars', array());
                $color_calendars = $this->rc->config->get('color_calendars', array());
                $alarm_calendars = $this->rc->config->get('alarm_calendars', array());
                unset($hidden_calendars[$id]);
                unset($active_calendars[$id]);
                unset($color_calendars[$id]);
                unset($alarm_calendars[$id]);
                $this->rc->user->save_prefs(array(
                    'color_calendars' => $color_calendars,
                    'active_calendars' => $active_calendars,
                    'alarm_calendars' => $alarm_calendars,
                    'hidden_calendars' => $hidden_calendars,
                ));
                return $this->calendars[$id]->delete();
            }
        }
        catch (LibMelanie\Exceptions\Melanie2DatabaseException $ex) {
            melanie2_logs::get_instance()->log(melanie2_logs::ERROR, "[calendar] melanie2_driver::remove_calendar() Melanie2DatabaseException");
            return false;
        }
        catch (\Exception $ex) {
            return false;
        }
        return false;
    }

    /**
     * Delete all the events in the given calendar
     *
     * @param array Hash array with calendar properties
     *      id: Calendar Identifier
     * @return boolean True on success, Fales on failure
     *
     * @see calendar_driver::remove_calendar()
     */
    public function delete_all_events($prop)
    {
        // Charge les données seulement si on est dans la tâche calendrier
        if ($this->rc->task != 'calendar') {
            return false;
        }
        $id = $this->_to_M2_id($prop['id']);
        melanie2_logs::get_instance()->log(melanie2_logs::DEBUG, "[calendar] melanie2_driver::delete_all_events($id)");
        melanie2_logs::get_instance()->log(melanie2_logs::TRACE, "[calendar] melanie2_driver::delete_all_events() : " . var_export($prop, true));

        try {
            // Chargement des calendriers si besoin
            if (!isset($this->calendars)) {
                $this->_read_calendars();
            }
            if (isset($id)
                    && isset($this->calendars[$id])
                    && $this->calendars[$id]->owner == $this->user->uid) {
                $calendar = $this->calendars[$id];
                // Récupération de tous
                $events = $calendar->getAllEvents();
                $result = true;
                // Parcours les évènements et les supprime
                foreach ($events as $event) {
                    $result &= $event->delete();
                }
                return $result;
            }
        }
        catch (LibMelanie\Exceptions\Melanie2DatabaseException $ex) {
            melanie2_logs::get_instance()->log(melanie2_logs::ERROR, "[calendar] melanie2_driver::delete_all_events() Melanie2DatabaseException");
            return false;
        }
        catch (\Exception $ex) {
            return false;
        }
        return false;
    }

    /**
     * Add a single event to the database
     *
     * @param array Hash array with event properties
     * @see calendar_driver::new_event()
     */
    public function new_event($event, $new = true)
    {
        // Charge les données seulement si on est dans la tâche calendrier
        if ($this->rc->task != 'calendar') {
            return false;
        }
        melanie2_logs::get_instance()->log(melanie2_logs::DEBUG, "[calendar] melanie2_driver::new_event(".$event['title'].", $new)");
        melanie2_logs::get_instance()->log(melanie2_logs::TRACE, "[calendar] melanie2_driver::new_event() : " . var_export($event, true));

        try {
            // Chargement des calendriers si besoin
            if (!isset($this->calendars)) {
                $this->_read_calendars();
            }
            $event['calendar'] = $this->_to_M2_id($event['calendar']);

            if (!$this->validate($event)
                    || empty($this->calendars)
                    || !isset($this->calendars[$event['calendar']])
                    || !$this->calendars[$event['calendar']]->asRight(LibMelanie\Config\ConfigMelanie::WRITE)) {
                return false;
            }
            // Récupère le timezone
            // Génère l'évènement
            $_event = new LibMelanie\Api\Melanie2\Event($this->user, $this->calendars[$event['calendar']]);
            // Calcul de l'uid de l'évènment
            if (isset($event['uid'])) {
                $_event->uid = $event['uid'];
            }
            elseif (isset($event['id'])) {
                $id = $event['id'];
                if (strpos($id, '@DATE-') !== false) {
                    $id = explode('@DATE-', $id);
                    $id = $id[0];
                }
                else if (strpos($id, self::RECURRENCE_ID) !== false) {
                    $id = substr($id, 0, strlen($id) - strlen(self::RECURRENCE_DATE.self::RECURRENCE_ID));
                }
                $_event->uid = $id;
                $event['uid'] = $id;
            }
            elseif ($new) {
                $_event->uid = date('Ymd').time().md5($event['calendar'].strval(time())).'@roundcube';
            }
            else {
                return false;
            }
            // Chargement de l'évènement pour savoir s'il s'agit d'un évènement privé donc non modifiable
            if ($_event->load()) {
                // Test si l'utilisateur est seulement participant
                $organizer = $_event->organizer;
                if (isset($organizer)
                        && !$organizer->extern
                        && !empty($organizer->email)
                        && $organizer->uid != $this->calendars[$event['calendar']]->owner) {
                    return true;
                }
                // Test si privé
                if (($_event->class == LibMelanie\Api\Melanie2\Event::CLASS_PRIVATE
                        || $_event->class == LibMelanie\Api\Melanie2\Event::CLASS_CONFIDENTIAL)
                        && $this->calendars[$_event->calendar]->owner != $this->user->uid
                        && !$this->calendars[$_event->calendar]->asRight(LibMelanie\Config\ConfigMelanie::PRIV)) {
                    return true;
                }
            }
            else {
                $_event->uid = str_replace('/', '', $_event->uid);
            }
            if (isset($event['_savemode'])
                    && $event['_savemode'] == 'current') {
                $_exception = new LibMelanie\Api\Melanie2\Exception($_event, $this->user, $this->calendars[$event['calendar']]);
                // Converti les données de l'évènement en exception Mélanie2
                $exceptions = $_event->exceptions;
                // Positionnement de la recurrenceId et de l'uid
                $id = $event['id'];
                if (strpos($id, '@DATE-') !== false) {
                    $recid = explode('@DATE-', $event['id']);
                    $recid = $recid[1];
                    $_exception->recurrenceId = date(self::SHORT_DB_DATE_FORMAT, intval($recid));
                }
                else if (strpos($id, self::RECURRENCE_ID) !== false) {
                    $recid = substr($id, strlen($id) - strlen(self::RECURRENCE_DATE.self::RECURRENCE_ID) + 1, -strlen(self::RECURRENCE_ID));
                    $_exception->recurrenceId = $recid;
                }
                else if ($event['start'] instanceof DateTime) {
                    $_exception->recurrenceId = $event['start']->format(self::SHORT_DB_DATE_FORMAT);
                }
                $_exception->uid = $event['uid'];
                $_exception->deleted = false;
                $exceptions[] = $this->_write_postprocess($_exception, $event, true);
                $_event->exceptions = $exceptions;
            } else if (isset($event['_savemode'])
                    && $event['_savemode'] == 'future') {
                // Définition de la date de fin pour la récurrence courante
                $enddate = clone ($event['start']);
                $enddate->sub(new DateInterval('P1D'));
                $_event->recurrence->enddate = $enddate->format(self::DB_DATE_FORMAT);
                $_event->save();
                // Création de la nouvelle
                $_event = new LibMelanie\Api\Melanie2\Event($this->user, $this->calendars[$event['calendar']]);
                // Converti les données de l'évènement en évènement Mélanie2
                $_event = $this->_write_postprocess($_event, $event, true);
                $_event->uid = $event['uid'] . "-" . strtotime($event['start']->format(self::DB_DATE_FORMAT)) . '@future';
            } else if (isset($event['_savemode'])
                    && $event['_savemode'] == 'new') {
                $event['uid'] = $_event->uid;
                // Création de la nouvelle
                $_event = new LibMelanie\Api\Melanie2\Event($this->user, $this->calendars[$event['calendar']]);
                // Converti les données de l'évènement en évènement Mélanie2
                $_event = $this->_write_postprocess($_event, $event, true);
                $_event->uid = $event['uid'] . "-" . strtotime($event['start']->format(self::DB_DATE_FORMAT)) . '@new';
            } else {
                // Converti les données de l'évènement en évènement Mélanie2
                $_event = $this->_write_postprocess($_event, $event, $new);
            }

            if ($_event->save() !== null) {
                // add attachments
                if (!empty($event['attachments'])) {
                    foreach ($event['attachments'] as $attachment) {
                        $this->add_attachment($attachment, $_event);
                        unset($attachment);
                    }
                }

                // remove attachments
                if (!empty($event['deleted_attachments'])) {
                    foreach ($event['deleted_attachments'] as $attachment) {
                        $this->remove_attachment($attachment);
                    }
                }
                return $_event->uid;
            }
        }
        catch (LibMelanie\Exceptions\Melanie2DatabaseException $ex) {
            melanie2_logs::get_instance()->log(melanie2_logs::ERROR, "[calendar] melanie2_driver::new_event() Melanie2DatabaseException");
            return false;
        }
        catch (\Exception $ex) {
            return false;
        }
        return false;
    }

    /**
     * Update an event entry with the given data
     *
     * @param array Hash array with event properties
     * @see calendar_driver::edit_event()
     */
    public function edit_event($event)
    {
        // Charge les données seulement si on est dans la tâche calendrier
        if ($this->rc->task != 'calendar') {
            return false;
        }
        melanie2_logs::get_instance()->log(melanie2_logs::DEBUG, "[calendar] melanie2_driver::edit_event()");
        melanie2_logs::get_instance()->log(melanie2_logs::TRACE, "[calendar] melanie2_driver::edit_event() : " . var_export($event, true));
        if ($this->new_event($event, false)) {
            if (isset($event['_fromcalendar'])) {
                $deleted_event = $event;
                $deleted_event['calendar'] = $event['_fromcalendar'];
                return $this->remove_event($deleted_event);
            }
            return true;
        }
        return false;
    }

    /**
     * Move a single event
     *
     * @param array Hash array with event properties
     * @see calendar_driver::move_event()
     */
    public function move_event($event)
    {
        // Charge les données seulement si on est dans la tâche calendrier
        if ($this->rc->task != 'calendar') {
            return false;
        }
        melanie2_logs::get_instance()->log(melanie2_logs::DEBUG, "[calendar] melanie2_driver::move_event()");
        melanie2_logs::get_instance()->log(melanie2_logs::TRACE, "[calendar] melanie2_driver::move_event() : " . var_export($event, true));

        try {
            // Chargement des calendriers si besoin
            if (!isset($this->calendars)) {
                $this->_read_calendars();
            }

            $event['calendar'] = $this->_to_M2_id($event['calendar']);

            if (!$this->validate($event)
                    || empty($this->calendars)
                    || !isset($this->calendars[$event['calendar']])
                    || !$this->calendars[$event['calendar']]->asRight(LibMelanie\Config\ConfigMelanie::WRITE)) {
                return false;
            }
            // Récupère le timezone
            // Génère l'évènement
            $_event = new LibMelanie\Api\Melanie2\Event($this->user, $this->calendars[$event['calendar']]);
            // Calcul de l'uid de l'évènment
            if (isset($event['uid'])) {
                $_event->uid = $event['uid'];
            }
            elseif (isset($event['id'])) {
                $id = $event['id'];
                if (strpos($id, '@DATE-') !== false) {
                    $id = explode('@DATE-', $id);
                    $id = $id[0];
                }
                else if (strpos($id, self::RECURRENCE_ID) !== false) {
                    $id = substr($id, 0, strlen($id) - strlen(self::RECURRENCE_DATE.self::RECURRENCE_ID));
                }
                $_event->uid = $id;
                $event['uid'] = $id;
            }
            else {
                return false;
            }
            // Chargement de l'évènement pour savoir s'il s'agit d'un évènement privé donc non modifiable
            if ($_event->load()) {
                // Test si l'utilisateur est seulement participant
                $organizer = $_event->organizer;
                if (isset($organizer)
                        && !$organizer->extern
                        && !empty($organizer->uid)
                        && $organizer->uid != $this->calendars[$event['calendar']]->owner) {
                    return true;
                }
                // Test si privé
                if (($_event->class == LibMelanie\Api\Melanie2\Event::CLASS_PRIVATE
                        || $_event->class == LibMelanie\Api\Melanie2\Event::CLASS_CONFIDENTIAL)
                        && $this->calendars[$_event->calendar]->owner != $this->user->uid
                        && !$this->calendars[$_event->calendar]->asRight(LibMelanie\Config\ConfigMelanie::PRIV)) {
                    return true;
                }
                if (isset($event['_savemode'])
                        && $event['_savemode'] == 'current') {
                    $_exception = new LibMelanie\Api\Melanie2\Exception($_event, $this->user, $this->calendars[$event['calendar']]);
                    // Converti les données de l'évènement en exception Mélanie2
                    $exceptions = $_event->exceptions;
                    if (!is_array($exceptions)) $exceptions = array();
                    $e = $this->_read_postprocess($_event);
                    unset($e['recurrence']);
                    $e['start'] = $event['start'];
                    $e['end'] = $event['end'];
                    $e['allday'] = $event['allday'];
                    // Positionnement de la recurrenceId et de l'uid
                    $id = $event['id'];
                    if (strpos($id, '@DATE-') !== false) {
                        $recid = explode('@DATE-', $event['id']);
                        $recid = $recid[1];
                        $_exception->recurrenceId = date(self::SHORT_DB_DATE_FORMAT, intval($recid));
                    }
                    else if (strpos($id, self::RECURRENCE_ID) !== false) {
                        $recid = substr($id, strlen($id) - strlen(self::RECURRENCE_DATE.self::RECURRENCE_ID) + 1, -strlen(self::RECURRENCE_ID));
                        $_exception->recurrenceId = $recid;
                    }
                    else if ($event['start'] instanceof DateTime) {
                        $_exception->recurrenceId = $event['start']->format(self::SHORT_DB_DATE_FORMAT);
                    }
                    $_exception->uid = $_event->uid;
                    $_exception->deleted = false;
                    // Génération de l'exception
                    $_exception = $this->_write_postprocess($_exception, $e, true);
                    $exceptions[] = $_exception;
                    $_event->exceptions = $exceptions;
                }
                else if (isset($event['_savemode'])
                        && $event['_savemode'] == 'future') {
                    $e = $this->_read_postprocess($_event);
                    // Génération de nouvel identifiant
                    $e['id'] = $e['id'] . "-" . strtotime($event['start']->format(self::DB_DATE_FORMAT)) . '@future';
                    $e['uid'] = $e['id'];
                    // Modification de la date
                    $e['start'] = $event['start'];
                    $e['end'] = $event['end'];
                    $e['allday'] = $event['allday'];
                    // Définition de la date de fin pour la récurrence courante
                    $enddate = clone ($event['start']);
                    $enddate->sub(new DateInterval('P1D'));
                    $_event->recurrence->enddate = $enddate->format(self::DB_DATE_FORMAT);
                    $_event->save();
                    // Création de la nouvelle
                    $_event = new LibMelanie\Api\Melanie2\Event($this->user, $this->calendars[$event['calendar']]);
                    // Converti les données de l'évènement en évènement Mélanie2
                    $_event = $this->_write_postprocess($_event, $e, true);
                    $_event->uid = $e['uid'];
                }
                else if (isset($event['_savemode'])
                        && $event['_savemode'] == 'new') {
                    // Génération de nouvel identifiant
                    $e['uid'] = $e['id'] . "-" . strtotime($event['start']->format(self::DB_DATE_FORMAT)) . '@new';
                    // Création de la nouvelle
                    $_event = new LibMelanie\Api\Melanie2\Event($this->user, $this->calendars[$event['calendar']]);
                    // Converti les données de l'évènement en évènement Mélanie2
                    $_event = $this->_write_postprocess($_event, $e, true);
                    $_event->uid = $e['uid'];
                }
                else {
                    // Converti les données de l'évènement en évènement Mélanie2
                    $_event = $this->_write_postprocess($_event, $event, false);
                }
                if ($_event->save() !== null) {
                    return $_event->uid;
                }
            }
        }
        catch (LibMelanie\Exceptions\Melanie2DatabaseException $ex) {
            melanie2_logs::get_instance()->log(melanie2_logs::ERROR, "[calendar] melanie2_driver::move_event() Melanie2DatabaseException");
            return false;
        }
        catch (\Exception $ex) {
            return false;
        }
        return false;
    }

    /**
     * Resize a single event
     *
     * @param array Hash array with event properties
     *
     * @see calendar_driver::resize_event()
     */
    public function resize_event($event)
    {
        // Charge les données seulement si on est dans la tâche calendrier
        if ($this->rc->task != 'calendar') {
            return false;
        }
        melanie2_logs::get_instance()->log(melanie2_logs::DEBUG, "[calendar] melanie2_driver::move_event()");
        melanie2_logs::get_instance()->log(melanie2_logs::TRACE, "[calendar] melanie2_driver::resize_event() : " . var_export($event, true));
        return $this->move_event($event);
    }

    /**
     * Convert a rcube style event object into sql record
     * @param LibMelanie\Api\Melanie2\Event $_event
     * @param array $event
     * @param boolean $new
     * @return LibMelanie\Api\Melanie2\Event $_event
     */
    private function _write_postprocess(LibMelanie\Api\Melanie2\Event $_event, $event, $new)
    {
        // Gestion des données de l'évènement
        if (isset($event['start'])) {
            if (isset($event['allday'])
                    && $event['allday'] == '1') {
                $_event->start = $event['start']->format(self::SHORT_DB_DATE_FORMAT).' 00:00:00';
            }
            else {
                $_event->start = $event['start'];
            }
        }
        if (isset($event['end'])) {
            if (isset($event['allday'])
                    && $event['allday'] == '1') {
                $event['end']->add(new DateInterval("P1D"));
                $_event->end = $event['end']->format(self::SHORT_DB_DATE_FORMAT).' 00:00:00';
            }
            else {
                $_event->end = $event['end'];
            }
        }
        if ($new) {
            $_event->owner = $this->user->uid;
        }
        if (isset($event['title'])) {
            $_event->title = strval($event['title']);
        }
        if (isset($event['description'])) {
            $_event->description = strval($event['description']);
        }
        if (isset($event['location'])) {
            $_event->location = strval($event['location']);
        }
        if (isset($event['categories'])) {
            $_event->category = strval($event['categories']);
        }
        // TODO: alarm
        if (isset($event['alarms'])) {
            $valarm = explode(':', $event['alarms']);
            if (isset($valarm[0])) {
                $_event->alarm = melanie2_mapping::valarm_ics_to_minutes_trigger($valarm[0]);
            }
        }
        // TODO: recurrence
        if (isset($event['recurrence'])
                && get_class($_event) != 'LibMelanie\Api\Melanie2\Exception') {
            $_event->recurrence = melanie2_mapping::RRule20_to_m2($event['recurrence'], $_event);
        }
        // Status
        if (isset($event['free_busy'])) {
            $_event->status = melanie2_mapping::rc_to_m2_status($event['free_busy']);
        }
        // Class
        if  (isset($event['sensitivity'])) {
            $_event->class = melanie2_mapping::rc_to_m2_class($event['sensitivity']);
        }
        // attendees
        if (isset($event['attendees'])
                && count($event['attendees']) > 0) {
            $_attendees = array();
            foreach ($event['attendees'] as $event_attendee) {
                if (isset($event_attendee['role'])
                        && $event_attendee['role'] == 'ORGANIZER') {
                    if (count($event['attendees']) != 1) {
                        $organizer = new LibMelanie\Api\Melanie2\Organizer($_event);
                        if (isset($event_attendee['email'])) {
                            $organizer->email = $event_attendee['email'];
                        }
                        if (isset($event_attendee['name'])) {
                            $organizer->name = $event_attendee['name'];
                        }
                        $_event->organizer = $organizer;
                    }
                }
                else {
                    $attendee = new LibMelanie\Api\Melanie2\Attendee();
                    if (isset($event_attendee['name'])) {
                        $attendee->name = $event_attendee['name'];
                    }
                    if (isset($event_attendee['email'])) {
                        $attendee->email = $event_attendee['email'];
                    }
                    // attendee role
                    if (isset($event_attendee['role'])) {
                        $attendee->role = melanie2_mapping::rc_to_m2_attendee_role($event_attendee['role']);
                    }
                    // attendee status
                    if (isset($event_attendee['status'])) {
                        $attendee->response = melanie2_mapping::rc_to_m2_attendee_status($event_attendee['status']);
                    }
                    $_attendees[] = $attendee;
                }
            }
            $_event->attendees = $_attendees;
        }
        // Modified time
        $_event->modified = time();

        return $_event;
    }

    /**
     * Remove a single event from the database
     *
     * @param array   Hash array with event properties
     * @param boolean Remove record irreversible (@TODO)
     *
     * @see calendar_driver::remove_event()
     */
    public function remove_event($event, $force = true)
    {
        // Charge les données seulement si on est dans la tâche calendrier
        if ($this->rc->task != 'calendar') {
            return false;
        }
        melanie2_logs::get_instance()->log(melanie2_logs::DEBUG, "[calendar] melanie2_driver::remove_event()");
        melanie2_logs::get_instance()->log(melanie2_logs::TRACE, "[calendar] melanie2_driver::remove_event() : " . var_export($event, true));

        try {
            // Chargement des calendriers si besoin
            if (!isset($this->calendars)) {
                $this->_read_calendars();
            }

            $event['calendar'] = $this->_to_M2_id($event['calendar']);

            if (empty($this->calendars)
                    || !isset($event['calendar'])
                    || !isset($this->calendars[$event['calendar']])
                    || !$this->calendars[$event['calendar']]->asRight(LibMelanie\Config\ConfigMelanie::WRITE)) {
                return false;
            }
            // Génère l'évènement
            $_event = new LibMelanie\Api\Melanie2\Event($this->user, $this->calendars[$event['calendar']]);
            if (isset($event['uid'])) {
                $_event->uid = $event['uid'];
            }
            elseif (isset($event['id'])) {
                $id = $event['id'];
                if (strpos($id, '@DATE-') !== false) {
                    $id = explode('@DATE-', $id);
                    $id = $id[0];
                }
                else if (strpos($id, self::RECURRENCE_ID) !== false) {
                    $id = substr($id, 0, strlen($id) - strlen(self::RECURRENCE_DATE.self::RECURRENCE_ID));
                }
                $_event->uid = $id;
            }
            else {
                return false;
            }
            if ($event['_savemode'] == 'all') {
                if ($_event->load()) {
                    foreach($_event->exceptions as $exception) {
                        $exception_uid = $exception->uid;
                        $exception->delete();
                        $this->remove_event_attachments($exception_uid);
                    }
                }
                $event_uid = $_event->uid;
                if ($_event->delete()) {
                    $this->remove_event_attachments($event_uid);
                    return true;
                } else {
                    return false;
                }
            } elseif ($event['_savemode'] == 'current') {
                if ($_event->load()) {
                    $_exception = new LibMelanie\Api\Melanie2\Exception($_event, $this->user, $this->calendars[$event['calendar']]);
                    // Converti les données de l'évènement en exception Mélanie2
                    $exceptions = $_event->exceptions;
                    // Positionnement de la recurrenceId et de l'uid
                    $id = $event['id'];
                    if (strpos($id, '@DATE-') !== false) {
                        $recid = explode('@DATE-', $event['id']);
                        $recid = $recid[1];
                        $_exception->recurrenceId = date(self::SHORT_DB_DATE_FORMAT, intval($recid));
                    }
                    else if (strpos($id, self::RECURRENCE_ID) !== false) {
                        $recid = substr($id, strlen($id) - strlen(self::RECURRENCE_DATE.self::RECURRENCE_ID) + 1, -strlen(self::RECURRENCE_ID));
                        $_exception->recurrenceId = $recid;
                    }
                    else if ($event['start'] instanceof DateTime) {
                        $_exception->recurrenceId = $event['start']->format(self::SHORT_DB_DATE_FORMAT);
                    }
                    $_exception->uid = $_event->uid;
                    $_exception->deleted = true;
                    // Supprimer la récurrence si elle est dans la liste
                    foreach ($exceptions as $key => $ex) {
                        if (date(self::SHORT_DB_DATE_FORMAT, strtotime($ex->recurrenceId)) == date(self::SHORT_DB_DATE_FORMAT, strtotime($_exception->recurrenceId))) {
                            $exceptions[$key]->delete();
                            unset($exceptions[$key]);
                        }
                    }
                    $exceptions[] = $_exception;
                    $_event->exceptions = $exceptions;
                    $ret = $_event->save();
                    return !is_null($ret);
                }
            } elseif ($event['_savemode'] == 'future') {
                if ($_event->load()) {
                    // Positionnement de la recurrenceId et de l'uid
                    $recid = explode('@DATE-', $event['id']);
                    $recid = $recid[1];
                    $_event->recurrence->enddate = date(self::SHORT_DB_DATE_FORMAT, intval($recid) - 24*60*60);
                    $_event->recurrence->count = 0;
                    if (strtotime($_event->recurrence->enddate) < strtotime($_event->start)) {
                        return $_event->delete();
                    } else {
                        $ret = $_event->save();
                        return !is_null($ret);
                    }
                }
            } else {
                $event_uid = $_event->uid;
                if ($_event->delete()) {
                    $this->remove_event_attachments($event_uid);
                    return true;
                } else {
                    return false;
                }
            }
        }
        catch (LibMelanie\Exceptions\Melanie2DatabaseException $ex) {
            melanie2_logs::get_instance()->log(melanie2_logs::ERROR, "[calendar] melanie2_driver::remove_event() Melanie2DatabaseException");
            return false;
        }
        catch (\Exception $ex) {
            return false;
        }
        return false;
    }

    /**
     * Return data of a specific event
     *
     * @param mixed  Hash array with event properties or event UID
     * @param boolean Only search in writeable calendars (ignored)
     * @param boolean Only search in active calendars
     * @param boolean Only search in personal calendars (ignored)
     *
     * @return array Hash array with event properties
     */
    public function get_event($event, $writeable = false, $active = false, $personal = false)
    {
        // Charge les données seulement si on est dans la tâche calendrier
        if ($this->rc->task != 'calendar') {
            return false;
        }
        melanie2_logs::get_instance()->log(melanie2_logs::DEBUG, "[calendar] melanie2_driver::get_event()");
        melanie2_logs::get_instance()->log(melanie2_logs::TRACE, "[calendar] melanie2_driver::get_event(writeable = $writeable, active = $active, personal = $personal) : " . var_export($event, true));

        try {
            // Chargement des calendriers si besoin
            if (!isset($this->calendars)) {
                $this->_read_calendars();
            }

            $event['calendar'] = $this->_to_M2_id($event['calendar']);

            if (isset($event['calendar'])
                    && isset($this->calendars[$event['calendar']])) {
                if (strpos($event['id'], '@RECURRENCE-ID') !== false) {

                }
                $_event = new LibMelanie\Api\Melanie2\Event($this->user, $this->calendars[$event['calendar']]);
                if (isset($event['uid'])) {
                    $_event->uid = $event['uid'];
                }
                elseif (isset($event['id'])) {
                    $_event->uid = $event['id'];
                }
                else {
                    return false;
                }
                if ($_event->load()) {
                    $event = $this->_read_postprocess($_event);

                    $attachments = (array)$this->list_attachments($_event);
                    if (count($attachments) > 0) {
                        $event['attachments'] = $attachments;
                    }
                    return $event;
                }
            } else {
                $calendars = $this->calendars;
                if ($active) {
                    foreach ($calendars as $idx => $cal) {
                        if (!$cal['active']) {
                            unset($calendars[$idx]);
                        }
                    }
                }
                foreach ($calendars as $cal) {
                    $_event = new LibMelanie\Api\Melanie2\Event($this->user, $cal);
                    if (isset($event['uid'])) {
                        $_event->uid = $event['uid'];
                    }
                    elseif (isset($event['id'])) {
                        $_event->uid = $event['id'];
                    }
                    else {
                        return false;
                    }
                    if ($_event->load()) {
                        $event = $this->_read_postprocess($_event);

                        $attachments = (array)$this->list_attachments($_event);
                        if (count($attachments) > 0) {
                            $event['attachments'] = $attachments;
                        }
                        return $event;
                    }
                }
            }
        }
        catch (LibMelanie\Exceptions\Melanie2DatabaseException $ex) {
            melanie2_logs::get_instance()->log(melanie2_logs::ERROR, "[calendar] melanie2_driver::get_event() Melanie2DatabaseException");
            return false;
        }
        catch (\Exception $ex) {
            return false;
        }
        return false;
    }

    /**
     * Get event data
     *
     * @see calendar_driver::load_events()
     */
    public function load_events($start, $end, $query = null, $calendars = null, $virtual = 1, $modifiedsince = null, $freebusy = false)
    {
        // Charge les données seulement si on est dans la tâche calendrier
        if ($this->rc->task != 'calendar') {
            return false;
        }
        melanie2_logs::get_instance()->log(melanie2_logs::DEBUG, "[calendar] melanie2_driver::load_events($start, $end, $query, $calendars)");

        try {
            // Chargement des calendriers si besoin
            if (!isset($this->calendars)) {
                $this->_read_calendars();
            }

            if (empty($calendars)) {
                $calendars = array_keys($this->calendars);
            }
            else if (is_string($calendars)) {
                $calendars = explode(',', $calendars);
            }
            if (count($calendars) == 0) {
                return array();
            }
            else {
                foreach ($calendars as $key => $value) {
                    $calendars[$key] = $this->_to_M2_id($value);
                }
            }

            $_events = array();
            $event = new LibMelanie\Api\Melanie2\Event($this->user);

            $cols = array('title','location','description','category');
            $operators = array();
            $filter = "#calendar#";
            $operators['calendar'] = LibMelanie\Config\MappingMelanie::eq;
            $event->calendar = $calendars;

            $filter .= " AND ((#start# AND #end#) OR (#type# AND #enddate#))";

            $operators['type'] = LibMelanie\Config\MappingMelanie::sup;
            $operators['enddate'] = LibMelanie\Config\MappingMelanie::supeq;
            $operators['start'] = LibMelanie\Config\MappingMelanie::infeq;
            $operators['end'] = LibMelanie\Config\MappingMelanie::supeq;

            $event->start = date("Y-m-d H:i:s", $end);
            $event->end = date("Y-m-d H:i:s", $start);
            $event->recurrence->type = LibMelanie\Api\Melanie2\Recurrence::RECURTYPE_NORECUR;
            $event->recurrence->enddate = date("Y-m-d H:i:s", $start);

            $case_unsensitive_fields = array();

            if (isset($query)) {
                $case_unsensitive_fields = $cols;
                $filter .= " AND (";
                $first = true;
                foreach ($cols as $col) {
                    if ($first) {
                        $first = false;
                    }
                    else {
                        $filter .= " OR ";
                    }
                    $filter .= "#$col#";
                    $operators[$col] = LibMelanie\Config\MappingMelanie::like;
                    $event->$col = "%$query%";
                }
                $filter .= ")";
            }
            // Liste les évènements modifiés depuis
            if (isset($modifiedsince)) {
                $event->modified = $modifiedsince;
                $operators['modified'] = LibMelanie\Config\MappingMelanie::supeq;
                $filter .= " AND #modified#";
            }
            $events = $event->getList(array(), $filter, $operators, "", true, null, null, $case_unsensitive_fields);
            foreach ($events as $_e) {
                if (!$freebusy && !$this->calendars[$_e->calendar]->asRight(LibMelanie\Config\ConfigMelanie::FREEBUSY)) {
                    continue;
                }
                if ($_e->recurrence->type === LibMelanie\Api\Melanie2\Recurrence::RECURTYPE_NORECUR
                        && !$_e->deleted) {
                    $_events[] = $this->_read_postprocess($_e, $freebusy);
                }
                else {
                    require_once($this->cal->home . '/lib/calendar_recurrence.php');
                    $_event = $this->_read_postprocess($_e, $freebusy);

                    if ($virtual) {
                        $recurrence = new calendar_recurrence($this->cal, $_event, new DateTime(date('Y-m-d H:i:s', $start - 60*60*60*24)));
                        // Pour la première occurrence, supprimer si une exception existe
                        $master = true;
                        foreach($_event['recurrence'][LibMelanie\Lib\ICS::EXDATE] as $_ex) {
                            // Si une exception a la même date que l'occurrence courante on ne l'affiche pas
                            if ($_ex->format(self::SHORT_DB_DATE_FORMAT) == $_event['start']->format(self::SHORT_DB_DATE_FORMAT)) {
                                $master = false;
                                break;
                            }
                        }
                        if ($master) {
                            // Ajoute l'évènement maitre pour afficher la première occurence
                            $_events[] = $_event;
                        }
                        // Parcour toutes les occurrences de la récurrence
                        while ($next_event = $recurrence->next_instance()) {
                            if (strtotime($next_event['end']->format(self::DB_DATE_FORMAT)) < $start) {
                                continue;
                            }
                            if (strtotime($next_event['start']->format(self::DB_DATE_FORMAT)) > $end) {
                                break;
                            }
                            // Ajout de la date de l'occurrence pour la récupérer lors des modifications
                            $next_event['id'] .= "@DATE-" . strtotime($next_event['start']->format('Y-m-d H:i:s'));
                            $_events[] = $next_event;
                        }
                        // Ajoute les exceptions
                        if (isset($_event['recurrence'])
                                && isset($_event['recurrence']['EXCEPTIONS'])
                                && count($_event['recurrence']['EXCEPTIONS']) > 0) {
                            foreach($_event['recurrence']['EXCEPTIONS'] as $_ex) {
                                $_events[] = $_ex;
                            }
                            unset($_event['recurrence']['EXCEPTIONS']);
                        }
                    }
                    else {
                        // Ajoute l'évènement maitre pour afficher la première occurence
                        $_events[] = $_event;
                    }
                }
            }
            //melanie2_logs::get_instance()->log(melanie2_logs::TRACE, "[calendar] melanie2_driver::load_events() events : " . var_export($_events, true));
            return $_events;
        }
        catch (LibMelanie\Exceptions\Melanie2DatabaseException $ex) {
            melanie2_logs::get_instance()->log(melanie2_logs::ERROR, "[calendar] melanie2_driver::load_events() Melanie2DatabaseException");
            return false;
        }
        catch (\Exception $ex) {
            return false;
        }
        return false;
    }

    /**
     * Convert sql record into a rcube style event object
     * @param LibMelanie\Api\Melanie2\Event $event
     */
    private function _read_postprocess(LibMelanie\Api\Melanie2\Event $event, $freebusy = false)
    {
        melanie2_logs::get_instance()->log(melanie2_logs::TRACE, "[calendar] melanie2_driver::_read_postprocess()");
        $_event = array();

        $_event['id'] = $event->uid;
        $_event['uid'] = $event->uid;

        // Evenement supprimé
        if ($event->deleted) {
            $_event['start'] = new DateTime('1970-01-01');
            $_event['end'] = new DateTime('1970-01-01');
            // Récupération des exceptions dans la récurrence de l'évènement
            $_event['recurrence'] = $this->_read_event_exceptions($event, array());
            return $_event;
        }

        // Dates
        // Savoir si c'est du journée entière (utilisation d'un endswith
        if (substr($event->start, -strlen('00:00:00')) === '00:00:00'
                && substr($event->end, -strlen('00:00:00')) === '00:00:00') {
            $_event['allday'] = true;
            $_event['start'] = new DateTime(substr($event->start, 0, strlen($event->start)-strlen('00:00:00')));
            $_event['end'] = new DateTime(substr($event->end, 0, strlen($event->end)-strlen('00:00:00')));
            // Supprimer un jour pour le décalage
            $_event['end']->sub(new DateInterval("P1D"));
        } else {
            $_event['start'] = new DateTime($event->start);
            $_event['end'] = new DateTime($event->end);
        }
        $_event['changed'] = new DateTime(date('Y-m-d H:i:s', $event->modified));
        $_event['calendar'] = $this->_to_RC_id($event->calendar);

        if ($freebusy) {
            // Status
            if (isset($event->status)) {
                $_event['free_busy'] = melanie2_mapping::m2_to_rc_status($event->status);
            }
        } else {
            // Test si privé
            $is_private = (($event->class == LibMelanie\Api\Melanie2\Event::CLASS_PRIVATE
                    || $event->class == LibMelanie\Api\Melanie2\Event::CLASS_CONFIDENTIAL)
                    && $this->calendars[$event->calendar]->owner != $this->user->uid
                    && !$this->calendars[$event->calendar]->asRight(LibMelanie\Config\ConfigMelanie::PRIV));

            $is_freebusy |= !$this->calendars[$event->calendar]->asRight(LibMelanie\Config\ConfigMelanie::READ)
                            && $this->calendars[$event->calendar]->asRight(LibMelanie\Config\ConfigMelanie::FREEBUSY);

            $owner = $this->calendars[$event->calendar]->owner;
            $user = $this->user->uid;
            $as_right = $this->calendars[$event->calendar]->asRight(LibMelanie\Config\ConfigMelanie::PRIV);

            // Status
            if (isset($event->status)) {
                $_event['free_busy'] = melanie2_mapping::m2_to_rc_status($event->status);
            }
            // Class
            if (isset($event->class)) {
                $_event['sensitivity'] = melanie2_mapping::m2_to_rc_class($event->class);
            }

            // Evenement privé
            if ($is_private) {
                $_event['title'] = $this->rc->gettext('event private', 'calendar');;
            }
            // Freebusy
            else if ($is_freebusy) {
                $_event['title'] = $this->rc->gettext('event '.$_event['free_busy'], 'calendar');
            }
            else {
                if (isset($event->title)) {
                    $_event['title'] = $event->title;
                }
                if (isset($event->description)) {
                    $_event['description'] = $event->description;
                }
                if (isset($event->location)) {
                    $_event['location'] = $event->location;
                }
                if (isset($event->category)) {
                    $_event['categories'] = $event->category;
                }
                // TODO: Alarme
                // Alarm
                if (isset($event->alarm) && $event->alarm != 0) {
                    if ($event->alarm > 0) {
                        $_event['alarms'] = "-".$event->alarm."M:DISPLAY";
                    }
                    else {
                        $_event['alarms'] = "+".str_replace('-', '', strval($event->alarm))."M:DISPLAY";
                    }
                }

                // Attendees
                $attendees = $event->attendees;
                if (isset($attendees)
                        && is_array($attendees)
                        && !empty($attendees)) {
                    $_attendees = array();
                    foreach($attendees as $attendee) {
                        $_event_attendee = array();
                        $_event_attendee['name'] = $attendee->name;
                        $_event_attendee['email'] = $attendee->email;
                        // role
                        $_event_attendee['role'] = melanie2_mapping::m2_to_rc_attendee_role($attendee->role);
                        // status
                        $_event_attendee['status'] = melanie2_mapping::m2_to_rc_attendee_status($attendee->response);
                        $_attendees[] = $_event_attendee;
                    }
                    $organizer = $event->organizer;
                    if (isset($organizer)) {
                        $_event_organizer = array();
                        $_event_organizer['email'] = $organizer->email;
                        $_event_organizer['name'] = $organizer->name;
                        $_event_organizer['role'] = 'ORGANIZER';
                        $_attendees[] = $_event_organizer;
                    }
                    $_event['attendees'] = $_attendees;
                }

                $attachments = (array)$this->list_attachments($event);
                if (count($attachments) > 0) {
                    $_event['attachments'] = $attachments;
                }
            }

            // Recurrence
            if (get_class($event) != 'LibMelanie\Api\Melanie2\Exception') {
                $recurrence = melanie2_mapping::m2_to_RRule20($event);
                if (is_array($recurrence)
                        && count($recurrence) > 0) {
                    // Récupération des exceptions dans la récurrence de l'évènement
                    $_event['recurrence'] = $this->_read_event_exceptions($event, $recurrence);
                }
            }
        }
        melanie2_logs::get_instance()->log(melanie2_logs::TRACE, "[calendar] melanie2_driver::_read_postprocess() event : " . var_export($_event, true));
        return $_event;
    }


    /**
     * Génère les exceptions dans la récurrence l'évènement
     *
     * @param LibMelanie\Api\Melanie2\Event $event
     * @param array $recurrence
     * @return array $recurrence
     */
    private function _read_event_exceptions(LibMelanie\Api\Melanie2\Event $event, $recurrence) {
        // Ajoute les exceptions
        $_exceptions = $event->exceptions;
        $deleted_exceptions = array();
        $recurrence['EXCEPTIONS'] = array();
        // Parcourir les exceptions
        foreach($_exceptions as $_exception) {
            if ($_exception->deleted) {
                $deleted_exceptions[] = new DateTime($_exception->recurrenceId);
            }
            else {
                // Génération de l'exception pour Roundcube
                // Ce tableau est ensuite dépilé pour être intégré a la liste des évènements
                $e = $this->_read_postprocess($_exception, $freebusy);
                $e['id'] = $_exception->realuid;
                $e['recurrence_id'] = $_exception->uid;
                $e['recurrence'] = $recurrence;
                $e['_instance'] = $_exception->recurrenceId;
                $e['isexception'] = 1;
                $deleted_exceptions[] = new DateTime($_exception->recurrenceId);
                $recurrence['EXCEPTIONS'][] = $e;
            }
        }
        // Ajoute les dates deleted
        $recurrence[LibMelanie\Lib\ICS::EXDATE] = $deleted_exceptions;
        return $recurrence;
    }


    /**
     * Get a list of pending alarms to be displayed to the user
     *
     * @see calendar_driver::pending_alarms()
     */
    public function pending_alarms($time, $calendars = null)
    {
        return;
        melanie2_logs::get_instance()->log(melanie2_logs::DEBUG, "[calendar] melanie2_driver::pending_alarms()");

        try {
            if (!isset($calendars)) {
                if (empty($this->calendars)) {
                    $this->_read_calendars();
                }
                $calendars = $this->calendars;
            }
            $calendars_id = array();
            $alarm_calendars = $this->rc->config->get('alarm_calendars', array());
            foreach ($calendars as $calendar) {
                if (isset($alarm_calendars[$calendar->id])) {
                    $calendars_id[] = $calendar->id;
                }
            }
            $_event = new LibMelanie\Api\Melanie2\Event($this->user);
            $_event->calendar = $calendars_id;
            $_event->alarm = 0;
            // Durée dans le passé maximum pour l'affichage des alarmes (2 semaines)
            $time_min = $time - 60*60*24*14;
            // Durée dans le futur maximum, basé sur la configuration du refresh
            $time_max = $time;
            // Clause Where
            $filter = "#calendar# AND #alarm# AND ((#start# - interval '1 minute' * k1.event_alarm) > '".date('Y-m-d H:i:s', $time_min)."') AND ((#start# - interval '1 minute' * k1.event_alarm) < '".date('Y-m-d H:i:s', $time_max)."')";
            // Operateur
            $operators = array(
                'alarm' => LibMelanie\Config\MappingMelanie::diff,
                'calendar' => LibMelanie\Config\MappingMelanie::in,
            );
            $fields = array('uid', 'title', 'calendar', 'start', 'end', 'location', 'alarm');
            $_events = $_event->getList($fields, $filter, $operators);
            $events = array();
            foreach($_events as $_event) {
                $eventproperty = new LibMelanie\Api\Melanie2\EventProperty($this->user, $_event);
                $eventproperty->key = array("X-MOZ-SNOOZE-TIME", "X-MOZ-LASTACK");
                $snoozetime = null;
                $lastack = null;
                $properties = $eventproperty->getList();
                // Récupération de la liste des attributs
                foreach ($properties as $property) {
                    $this->attributes[$property->key] = $property;
                    if ($property->key == "X-MOZ-SNOOZE-TIME") {
                        $snoozetime = strtotime($property->value);
                    }
                    elseif ($property->key == "X-MOZ-LASTACK") {
                        $lastack = strtotime($property->value);
                    }
                }
                if (isset($lastack)) {
                    if ($lastack > (strtotime($_event->start) - ($_event->alarm * 60))) {
                        continue;
                    }
                }
                if (isset($snoozetime)) {
                    if ($snoozetime > $time) {
                        continue;
                    }
                }
                $_e = $this->_read_postprocess($_event);
                // Ajoute les exceptions
                if (isset($_e['recurrence'])
                    && isset($_e['recurrence']['EXCEPTIONS'])
                    && count($_e['recurrence']['EXCEPTIONS']) > 0) {
                  foreach($_e['recurrence']['EXCEPTIONS'] as $_ex) {
                    $events[] = $_ex;
                  }
                  unset($_e['recurrence']['EXCEPTIONS']);
                }
                $events[] = $_e;
            }
            return $events;
        }
        catch (LibMelanie\Exceptions\Melanie2DatabaseException $ex) {
            melanie2_logs::get_instance()->log(melanie2_logs::ERROR, "[calendar] melanie2_driver::pending_alarms() Melanie2DatabaseException");
            return false;
        }
        catch (\Exception $ex) {
            return false;
        }
        return false;
    }

    /**
     * Feedback after showing/sending an alarm notification
     *
     * @see calendar_driver::dismiss_alarm()
     */
    public function dismiss_alarm($event_id, $snooze = 0)
    {
        melanie2_logs::get_instance()->log(melanie2_logs::DEBUG, "[calendar] melanie2_driver::dismiss_alarm($event_id)");
        try {
            if (!isset($calendars)) {
                if (empty($this->calendars)) {
                    $this->_read_calendars();
                }
                $calendars = $this->calendars;
            }
            // Parcourir les agendas pour se limité à ceux qui affiche les alarmes
            $alarm_calendars = $this->rc->config->get('alarm_calendars', array());
            foreach($calendars as $key => $calendar) {
                if (isset($alarm_calendars[$calendar->id])) {
                    $event = new LibMelanie\Api\Melanie2\Event($this->user, $calendar);
                    $event->uid = $event_id;
                    if ($event->load()) {
                        $eventproperty = new LibMelanie\Api\Melanie2\EventProperty($this->user, $event);
                        if ($snooze != 0) {
                            $eventproperty->key = 'X-MOZ-SNOOZE-TIME';
                            $time = time() + $snooze;
                            $eventproperty->value = gmdate('Ymd', $time).'T'.gmdate('His', $time).'Z';
                            $eventproperty->save();
                        }
                        else {
                            $eventproperty->key = 'X-MOZ-LASTACK';
                            $time = time();
                            $eventproperty->value = gmdate('Ymd', $time).'T'.gmdate('His', $time).'Z';
                            $eventproperty->save();
                        }
                    }
                }
            }
            return true;
        }
        catch (LibMelanie\Exceptions\Melanie2DatabaseException $ex) {
            melanie2_logs::get_instance()->log(melanie2_logs::ERROR, "[calendar] melanie2_driver::dismiss_alarm() Melanie2DatabaseException");
            return false;
        }
        catch (\Exception $ex) {
            return false;
        }
        return false;
    }

    /**
     * Save an attachment related to the given event
     * @param array $attachment
     * @param LibMelanie\Api\Melanie2\Event $event
     * @return boolean
     */
    private function add_attachment($attachment, LibMelanie\Api\Melanie2\Event $event)
    {
        melanie2_logs::get_instance()->log(melanie2_logs::DEBUG, "[calendar] melanie2_driver::add_attachment($event_id)");
        try {
            $organizer = $event->organizer;
            // Ne pas ajouter de pièce jointe si on n'est pas organisateur (et que l'organisateur est au ministère
            if (isset($organizer)
                    && !$organizer->extern
                    && !empty($organizer->email)
                    && $organizer->uid != $this->calendars[$event->calendar]->owner) {
                return true;
            }
            // Creation du dossier
            $_folder = new LibMelanie\Api\Melanie2\Attachment();
            $_folder->name = $event->uid;
            $_folder->path = '';
            if (!$_folder->load()) {
                $_folder->isfolder = true;
                $_folder->modified = time();
                $_folder->owner = $this->user->uid;
                $_folder->save();
            }
            // Creation du dossier
            $_folder = new LibMelanie\Api\Melanie2\Attachment();
            $_folder->name = $this->calendars[$event->calendar]->owner;
            $_folder->path = $event->uid;
            if (!$_folder->load()) {
                $_folder->isfolder = true;
                $_folder->modified = time();
                $_folder->owner = $this->user->uid;
                $_folder->save();
            }

            // Création de la pièce jointe
            $_attachment = new LibMelanie\Api\Melanie2\Attachment();
            $_attachment->modified = time();
            $_attachment->name = $attachment['name'];
            $_attachment->path = $event->uid . '/' . $this->calendars[$event->calendar]->owner;
            $_attachment->owner = $this->user->uid;
            $_attachment->isfolder = false;
            $_attachment->data = $attachment['data'];
            $ret = $_attachment->save();
            return !is_null($ret);
        }
        catch (LibMelanie\Exceptions\Melanie2DatabaseException $ex) {
            melanie2_logs::get_instance()->log(melanie2_logs::ERROR, "[calendar] melanie2_driver::add_attachment() Melanie2DatabaseException");
            return false;
        }
        catch (\Exception $ex) {
            return false;
        }
        return false;
    }

    /**
     * Remove a specific attachment from the given event
     * @param string $attachment_id
     * @return boolean
     */
    private function remove_attachment($attachment_id)
    {
        melanie2_logs::get_instance()->log(melanie2_logs::DEBUG, "[calendar] melanie2_driver::remove_attachment($attachment_id, $event_id)");
        try {
            $attachment = new LibMelanie\Api\Melanie2\Attachment();
            $attachment->isfolder = false;
            $attachment->id = $attachment_id;
            $ret = true;
            foreach($attachment->getList() as $att) {
                // Vérifie si d'autres pièces jointes sont présentes
                $other_attachment = new LibMelanie\Api\Melanie2\Attachment();
                $other_attachment->isfolder = false;
                $other_attachment->path = $att->path;
                $ret = $ret & $att->delete();
                $other_att = $other_attachment->getList();
                if (count($other_att) == 0) {
                    // S'il n'y a pas d'autres pieces jointes on supprime le dossier
                    $path = explode('/', $other_attachment->path);
                    $folder = new LibMelanie\Api\Melanie2\Attachment();
                    $folder->isfolder = true;
                    $folder->name = $path[count($path)-1];
                    $folder->path = $path[count($path)-2];
                    $ret = $ret & $folder->delete();
                }
            }
            return $ret;
        }
        catch (LibMelanie\Exceptions\Melanie2DatabaseException $ex) {
            melanie2_logs::get_instance()->log(melanie2_logs::ERROR, "[calendar] melanie2_driver::remove_attachment() Melanie2DatabaseException");
            return false;
        }
        catch (\Exception $ex) {
            return false;
        }
        return false;
    }
    /**
     * Remove all attachments for a deleted event
     * @param string $event_uid
     */
    private function remove_event_attachments($event_uid)
    {
        try {
            $_events = new LibMelanie\Api\Melanie2\Event();
            $_events->uid = $event_uid;
            $nb_events = $_events->getList('count');
            $count = $nb_events['']->events_count;
            unset($nb_events);
            // Si c'est le dernier evenement avec le même uid on supprime toutes les pièces jointes
            if ($count === 0) {
                $attachments_folders = new LibMelanie\Api\Melanie2\Attachment();
                $attachments_folders->isfolder = true;
                $attachments_folders->path = $event_uid;
                $folders_list = array();
                // Récupère les dossiers lié à l'évènement
                $folders = $attachments_folders->getList();
                if (count($folders) > 0) {
                    foreach($folders as $folder) {
                        $folders_list[] = $folder->path . '/' . $folder->name;
                    }
                    $attachments = new LibMelanie\Api\Melanie2\Attachment();
                    $attachments->isfolder = false;
                    $attachments->path = $folders_list;
                    // Lecture des pièces jointes pour chaque dossier de l'évènement
                    $attachments = $attachments->getList(array('id', 'name', 'path'));
                    if (count($attachments) > 0) {
                        foreach($attachments as $attachment) {
                            // Supprime la pièce jointe
                            $attachment->delete();
                        }
                    }
                    foreach($folders as $folder) {
                        // Supprime le dossier
                        $folder->delete();
                    }
                }
                $folder = new LibMelanie\Api\Melanie2\Attachment();
                $folder->isfolder = true;
                $folder->path = '';
                $folder->name = $event_uid;
                if ($folder->load()) {
                    $folder->delete();
                }
            }
        }
        catch (LibMelanie\Exceptions\Melanie2DatabaseException $ex) {
            melanie2_logs::get_instance()->log(melanie2_logs::ERROR, "[calendar] melanie2_driver::remove_event_attachments() Melanie2DatabaseException");
            return false;
        }
        catch (\Exception $ex) {
            return false;
        }
        return false;
    }

    /**
     * List attachments of specified event
     */
    public function list_attachments($event)
    {
        melanie2_logs::get_instance()->log(melanie2_logs::DEBUG, "[calendar] melanie2_driver::list_attachments()");
        try {
            $_attachments = array();
            // Récupération des pièces jointes
            $attachments_folders = new LibMelanie\Api\Melanie2\Attachment();
            $attachments_folders->isfolder = true;
            $attachments_folders->path = $event->uid;
            $folders_list = array();
            // Récupère les dossiers lié à l'évènement
            $folders = $attachments_folders->getList();
            if (count($folders) > 0) {
                foreach($folders as $folder) {
                    $folders_list[] = $folder->path . '/' . $folder->name;
                }
                $attachments = new LibMelanie\Api\Melanie2\Attachment();
                $attachments->isfolder = false;
                $attachments->path = $folders_list;
                // Lecture des pièces jointes pour chaque dossier de l'évènement
                $attachments = $attachments->getList(array('id', 'name'));
                if (count($attachments) > 0) {
                    foreach($attachments as $attachment) {
                        $_attachment = array(
                            'id' => $attachment->id,
                            'name' => $attachment->name,
                        );
                        $_attachments[] = $_attachment;
                    }
                }
            }
            return $_attachments;
        }
        catch (LibMelanie\Exceptions\Melanie2DatabaseException $ex) {
            melanie2_logs::get_instance()->log(melanie2_logs::ERROR, "[calendar] melanie2_driver::list_attachments() Melanie2DatabaseException");
            return false;
        }
        catch (\Exception $ex) {
            return false;
        }
        return false;
    }

    /**
     * Get attachment properties
     */
    public function get_attachment($id, $event)
    {
        melanie2_logs::get_instance()->log(melanie2_logs::DEBUG, "[calendar] melanie2_driver::get_attachment($id)");
        try {
            $attachment = new LibMelanie\Api\Melanie2\Attachment();
            $attachment->isfolder = false;
            $attachment->id = $id;
            foreach($attachment->getList() as $att) {
                $ret =  array(
                    'id' => $att->id,
                    'name' => $att->name,
                    'mimetype' => $att->contenttype,
                    'size' => $att->size,
                );
                $this->attachment = $att;
                return $ret;
            }
        }
        catch (LibMelanie\Exceptions\Melanie2DatabaseException $ex) {
            melanie2_logs::get_instance()->log(melanie2_logs::ERROR, "[calendar] melanie2_driver::get_attachment() Melanie2DatabaseException");
            return false;
        }
        catch (\Exception $ex) {
            return false;
        }
        return false;
    }

    /**
     * Get attachment body
     */
    public function get_attachment_body($id, $event)
    {
        melanie2_logs::get_instance()->log(melanie2_logs::DEBUG, "[calendar] melanie2_driver::get_attachment_body($id)");
        if (isset($this->attachment)) {
            return $this->attachment->data;
        }
        return false;
    }

    /**
     * Fetch free/busy information from a person within the given range
     */
    public function get_freebusy_list($email, $start, $end)
    {
        try {
            // Récupération de l'utilisateur depuis le serveur LDAP
            $infos = LibMelanie\Ldap\LDAPMelanie::GetInformationsFromMail($email);
            if (isset($infos)
                    && is_array($infos['uid'])
                    && count($infos['uid']) > 0) {
                // map vcalendar fbtypes to internal values
                $fbtypemap = array(
                    'free' => calendar::FREEBUSY_FREE,
                    'tentative' => calendar::FREEBUSY_TENTATIVE,
                    'outofoffice' => calendar::FREEBUSY_OOF,
                    'busy' => calendar::FREEBUSY_BUSY);
                // Si l'utilisateur appartient au ministère, on génère ses freebusy
                $uid = $infos['uid'][0];
                // Utilisation du load_events pour charger les évènements déjà formattés (récurrences)
                $events = $this->load_events($start, $end, null, $infos['uid'][0], 1, null, true);
                $result = array();
                foreach ($events as $event) {
                    if ($event['allday']) {
                        $from = strtotime($event['start']->format(self::SHORT_DB_DATE_FORMAT));
                        $to = strtotime($event['end']->format(self::SHORT_DB_DATE_FORMAT));
                    } else {
                        $from = strtotime($event['start']->format(self::DB_DATE_FORMAT));
                        $to = strtotime($event['end']->format(self::DB_DATE_FORMAT));
                    }
                    $result[] = array($from, $to, isset($fbtypemap[$event['free_busy']]) ? $fbtypemap[$event['free_busy']] : calendar::FREEBUSY_BUSY);
                }
                return $result;
            }
            else {
                // map vcalendar fbtypes to internal values
                $fbtypemap = array(
                    'FREE' => calendar::FREEBUSY_FREE,
                    'BUSY-TENTATIVE' => calendar::FREEBUSY_TENTATIVE,
                    'X-OUT-OF-OFFICE' => calendar::FREEBUSY_OOF,
                    'OOF' => calendar::FREEBUSY_OOF);

                // Si l'utilisateur n'appartient pas au minitère, on récupère éventuellement les freebusy depuis les contacts
                $fburl = null;
                foreach ((array)$this->rc->config->get('autocomplete_addressbooks', 'sql') as $book) {
                    $abook = $this->rc->get_address_book($book);

                    if ($result = $abook->search(array('email'), $email, true, true, true/*, 'freebusyurl'*/)) {
                        while ($contact = $result->iterate()) {
                            if ($fburl = $contact['freebusyurl']) {
                                $fbdata = @file_get_contents($fburl);
                                break;
                            }
                        }
                    }

                    if ($fbdata)
                        break;
                }

                // parse free-busy information using Horde classes
                if ($fbdata) {
                    $fbcal = $this->cal->get_ical()->get_parser();
                    $fbcal->parsevCalendar($fbdata);
                    if ($fb = $fbcal->findComponent('vfreebusy')) {
                        $result = array();
                        $params = $fb->getExtraParams();
                        foreach ($fb->getBusyPeriods() as $from => $to) {
                            if ($to == null)  // no information, assume free
                                break;
                            $type = $params[$from]['FBTYPE'];
                            $result[] = array($from, $to, isset($fbtypemap[$type]) ? $fbtypemap[$type] : calendar::FREEBUSY_BUSY);
                        }

                        // we take 'dummy' free-busy lists as "unknown"
                        if (empty($result) && ($comment = $fb->getAttribute('COMMENT')) && stripos($comment, 'dummy'))
                            return false;

                        // set period from $start till the begin of the free-busy information as 'unknown'
                        if (($fbstart = $fb->getStart()) && $start < $fbstart) {
                            array_unshift($result, array($start, $fbstart, calendar::FREEBUSY_UNKNOWN));
                        }
                        // pad period till $end with status 'unknown'
                        if (($fbend = $fb->getEnd()) && $fbend < $end) {
                            $result[] = array($fbend, $end, calendar::FREEBUSY_UNKNOWN);
                        }

                        return $result;
                    }
                }

            }
        }
        catch (LibMelanie\Exceptions\Melanie2DatabaseException $ex) {
            melanie2_logs::get_instance()->log(melanie2_logs::ERROR, "[calendar] melanie2_driver::get_freebusy_list() Melanie2DatabaseException");
            return false;
        }
        catch (\Exception $ex) {
            return false;
        }
        return false;
    }

    /**
     * List availabale categories
     * The default implementation reads them from config/user prefs
     */
    public function list_categories()
    {
        melanie2_logs::get_instance()->log(melanie2_logs::DEBUG, "[calendar] melanie2_driver::list_categories()");
        try {
            // Récupère la liste des catégories
            $pref_categories = new LibMelanie\Api\Melanie2\UserPrefs($this->user);
            $pref_categories->name = "categories";
            $pref_categories->scope = LibMelanie\Config\ConfigMelanie::GENERAL_PREF_SCOPE;
            if (!$pref_categories->load()) {
                $_categories = array();
            }
            else {
                $_categories = explode('|', $pref_categories->value);
            }
            // Récupère la liste des couleurs des catégories (sic)
            $pref_categories_colors = new LibMelanie\Api\Melanie2\UserPrefs($this->user);
            $pref_categories_colors->name = "category_colors";
            $pref_categories_colors->scope = LibMelanie\Config\ConfigMelanie::GENERAL_PREF_SCOPE;
            if (!$pref_categories_colors->load()) {
                $_categories_color = array();
            }
            else {
                $_categories_color = explode('|', $pref_categories_colors->value);
            }
            $categories_colors = array();
            foreach ($_categories_color as $_category_color) {
                // Sépare les couleurs dans les paramètres de horde
                $c = explode(':', $_category_color);
                if (isset($c[0]) && isset($c[1])) {
                    $categories_colors[$c[0]] = $c[1];
                }
            }
            // Génération du tableau contenant les catégories et leur couleurs
            $categories = array();
            foreach ($_categories as $_category) {
                if (isset($categories_colors[$_category])) {
                    $categories[$_category] = str_replace('#', '', $categories_colors[$_category]);
                }
                else {
                    // La catégory n'a pas de couleur, on en choisi une par défaut
                    $categories[$_category] = 'c0c0c0';
                }
            }
            ksort($categories);
            return $categories;
        }
        catch (LibMelanie\Exceptions\Melanie2DatabaseException $ex) {
            melanie2_logs::get_instance()->log(melanie2_logs::ERROR, "[calendar] melanie2_driver::list_categories() Melanie2DatabaseException");
            return false;
        }
        catch (\Exception $ex) {
            return false;
        }
        return false;
    }

    /**
     * Create a new category
     */
    public function add_category($name, $color) {
        melanie2_logs::get_instance()->log(melanie2_logs::DEBUG, "[calendar] melanie2_driver::add_category($name, $color)");
        try {
            // Récupère la liste des catégories
            $pref_categories = new LibMelanie\Api\Melanie2\UserPrefs($this->user);
            $pref_categories->name = "categories";
            $pref_categories->scope = LibMelanie\Config\ConfigMelanie::GENERAL_PREF_SCOPE;
            $pref_categories->load();
            // Ajoute la nouvelle valeur
            if (isset($pref_categories->value)
                    && $pref_categories->value != "") {
                $pref_categories->value .= "|";
            }
            $pref_categories->value .= "$name";
            $pref_categories->save();

            // Récupère la liste des couleurs des catégories (sic)
            $pref_categories_colors = new LibMelanie\Api\Melanie2\UserPrefs($this->user);
            $pref_categories_colors->name = "category_colors";
            $pref_categories_colors->scope = LibMelanie\Config\ConfigMelanie::GENERAL_PREF_SCOPE;
            $pref_categories_colors->load();
            // Ajoute la nouvelle valeur et couleur
            if (isset($pref_categories_colors->value)
                    && $pref_categories_colors->value != "") {
                $pref_categories_colors->value .= "|";
            }
            $pref_categories_colors->value .= "$name:#$color";
            $pref_categories_colors->save();
        }
        catch (LibMelanie\Exceptions\Melanie2DatabaseException $ex) {
            melanie2_logs::get_instance()->log(melanie2_logs::ERROR, "[calendar] melanie2_driver::add_category() Melanie2DatabaseException");
            return false;
        }
        catch (\Exception $ex) {
            return false;
        }
        return false;
    }

    /**
     * Remove the given category
     */
    public function remove_category($name) {
        melanie2_logs::get_instance()->log(melanie2_logs::DEBUG, "[calendar] melanie2_driver::remove_category($name)");
        try {
            // Récupère la liste des catégories
            $pref_categories = new LibMelanie\Api\Melanie2\UserPrefs($this->user);
            $pref_categories->name = "categories";
            $pref_categories->scope = LibMelanie\Config\ConfigMelanie::GENERAL_PREF_SCOPE;
            if (!$pref_categories->load()) {
                $_categories = array();
            }
            else {
                $_categories = explode('|', $pref_categories->value);
            }
            // Supprime la valeur dans la liste
            $change = false;
            foreach ($_categories as $key => $_category) {
                if ($_category == $name) {
                    unset($_categories[$key]);
                    $change = true;
                }
            }
            // Enregistre la nouvelle liste si elle a changé
            if ($change) {
                $pref_categories->value = implode('|', $_categories);
                $pref_categories->save();
            }

            // Récupère la liste des couleurs des catégories (sic)
            $pref_categories_colors = new LibMelanie\Api\Melanie2\UserPrefs($this->user);
            $pref_categories_colors->name = "category_colors";
            $pref_categories_colors->scope = LibMelanie\Config\ConfigMelanie::GENERAL_PREF_SCOPE;
            if (!$pref_categories_colors->load()) {
                $_categories_color = array();
            }
            else {
                $_categories_color = explode('|', $pref_categories_colors->value);
            }
            // Supprime la valeur dans la liste
            $change = false;
            foreach ($_categories_color as $key => $_category_color) {
                // Sépare les couleurs dans les paramètres de horde
                $c = explode(':', $_category_color);
                if (isset($c[0]) && $c[0] == $name) {
                    unset($_categories_color[$key]);
                    $change = true;
                }
            }
            // Enregistre la nouvelle liste si elle a changé
            if ($change) {
                $pref_categories_colors->value = implode('|', $_categories_color);
                $pref_categories_colors->save();
            }
        }
        catch (LibMelanie\Exceptions\Melanie2DatabaseException $ex) {
            melanie2_logs::get_instance()->log(melanie2_logs::ERROR, "[calendar] melanie2_driver::remove_category() Melanie2DatabaseException");
            return false;
        }
        catch (\Exception $ex) {
            return false;
        }
        return false;
    }

    /**
     * Update/replace a category
     */
    public function replace_category($oldname, $name, $color) {
        melanie2_logs::get_instance()->log(melanie2_logs::DEBUG, "[calendar] melanie2_driver::replace_category($oldname, $name, $color)");
        try {
            // Récupère la liste des catégories
            $pref_categories = new LibMelanie\Api\Melanie2\UserPrefs($this->user);
            $pref_categories->name = "categories";
            $pref_categories->scope = LibMelanie\Config\ConfigMelanie::GENERAL_PREF_SCOPE;
            if (!$pref_categories->load()) {
                $_categories = array();
            }
            else {
                $_categories = explode('|', $pref_categories->value);
            }
            // Supprime la valeur dans la liste
            $change = false;
            foreach ($_categories as $key => $_category) {
                if ($_category == $oldname) {
                    $_categories[$key] = $name;
                    $change = true;
                }
            }
            // Enregistre la nouvelle liste si elle a changé
            if ($change) {
                $pref_categories->value = implode('|', $_categories);
                $pref_categories->save();
            }

            // Récupère la liste des couleurs des catégories (sic)
            $pref_categories_colors = new LibMelanie\Api\Melanie2\UserPrefs($this->user);
            $pref_categories_colors->name = "category_colors";
            $pref_categories_colors->scope = LibMelanie\Config\ConfigMelanie::GENERAL_PREF_SCOPE;
            if (!$pref_categories_colors->load()) {
                $_categories_color = array();
            }
            else {
                $_categories_color = explode('|', $pref_categories_colors->value);
            }
            // Supprime la valeur dans la liste
            $change = false;
            foreach ($_categories_color as $key => $_category_color) {
                // Sépare les couleurs dans les paramètres de horde
                $c = explode(':', $_category_color);
                if (isset($c[0]) && $c[0] == $oldname
                        && $_category_color != "$name:#$color") {
                    $_categories_color[$key] = "$name:#$color";
                    $change = true;
                }
            }
            // Enregistre la nouvelle liste si elle a changé
            if ($change) {
                $pref_categories_colors->value = implode('|', $_categories_color);
                $pref_categories_colors->save();
            }
        }
        catch (LibMelanie\Exceptions\Melanie2DatabaseException $ex) {
            melanie2_logs::get_instance()->log(melanie2_logs::ERROR, "[calendar] melanie2_driver::replace_category() Melanie2DatabaseException");
            return false;
        }
        catch (\Exception $ex) {
            return false;
        }
        return false;
    }

    /**
     * Callback function to produce driver-specific calendar create/edit form
     *
     * @param string Request action 'form-edit|form-new'
     * @param array  Calendar properties (e.g. id, color)
     * @param array  Edit form fields
     *
     * @return string HTML content of the form
     */
    public function calendar_form($action, $calendar, $formfields) {
        // Charge les données seulement si on est dans la tâche calendrier
        if ($this->rc->task != 'calendar') {
            return false;
        }
        melanie2_logs::get_instance()->log(melanie2_logs::DEBUG, "[calendar] melanie2_driver::calendar_form($calendar)");

        try {
            // Chargement des calendriers si besoin
            if (!isset($this->calendars)) {
                $this->_read_calendars();
            }
            $calendar['id'] = $this->_to_M2_id($calendar['id']);

            if ($calendar['id'] && ($cal = $this->calendars[$calendar['id']])) {
                $folder = $cal->name; // UTF7
                $color_calendars = $this->rc->config->get('color_calendars', array());
                if (isset($color_calendars[$cal->id])) $color = $color_calendars[$cal->id];
                else $color  = '';
            }
            else {
                $folder = '';
                $color  = '';
            }

            $hidden_fields[] = array('name' => 'oldname', 'value' => $folder);

            $storage = $this->rc->get_storage();
            $delim   = $storage->get_hierarchy_delimiter();
            $form   = array();

            if (strlen($folder)) {
                $path_imap = explode($delim, $folder);
                array_pop($path_imap);  // pop off name part
                $path_imap = implode($path_imap, $delim);

                $options = $storage->folder_info($folder);
            }
            else {
                $path_imap = '';
            }

            // General tab
            $form['props'] = array(
                'name' => $this->rc->gettext('properties'),
            );

            // Disable folder name input
            if ($action != 'form-new'
                    && $cal->owner != $this->user->uid) {
                $input_name = new html_hiddenfield(array('name' => 'name', 'id' => 'calendar-name'));
                $formfields['name']['value'] = $folder
                . $input_name->show($folder);
            }

            // calendar name (default field)
            $form['props']['fieldsets']['location'] = array(
                'name'  => $this->rc->gettext('location'),
                'content' => array(
                    'name' => $formfields['name']
                ),
            );

            // calendar color (default field)
            $form['props']['fieldsets']['settings'] = array(
                'name'  => $this->rc->gettext('settings'),
                'content' => array(
                    'color' => $formfields['color'],
                    'showalarms' => $formfields['showalarms'],
                ),
            );


            if ($action != 'form-new'
                    && $cal->owner == $this->user->uid) {
                $form['sharing'] = array(
                    'name'    => Q($this->cal->gettext('tabsharing')),
                    'content' => html::tag('iframe', array(
                        'src' => $this->cal->rc->url(array('_action' => 'calendar-acl', 'id' => $calendar['id'], 'framed' => 1)),
                        'width' => '100%',
                        'height' => 350,
                        'border' => 0,
                        'style' => 'border:0'),
                            ''),
                );
                $form['groupsharing'] = array(
                    'name'    => Q($this->cal->gettext('tabsharinggroup')),
                    'content' => html::tag('iframe', array(
                        'src' => $this->cal->rc->url(array('_action' => 'calendar-acl-group', 'id' => $calendar['id'], 'framed' => 1)),
                        'width' => '100%',
                        'height' => 350,
                        'border' => 0,
                        'style' => 'border:0'),
                            ''),
                );
            }

            $this->form_html = '';
            if (is_array($hidden_fields)) {
                foreach ($hidden_fields as $field) {
                    $hiddenfield = new html_hiddenfield($field);
                    $this->form_html .= $hiddenfield->show() . "\n";
                }
            }

            // Create form output
            foreach ($form as $tab) {
                if (!empty($tab['fieldsets']) && is_array($tab['fieldsets'])) {
                    $content = '';
                    foreach ($tab['fieldsets'] as $fieldset) {
                        $subcontent = $this->get_form_part($fieldset);
                        if ($subcontent) {
                            $content .= html::tag('fieldset', null, html::tag('legend', null, Q($fieldset['name'])) . $subcontent) ."\n";
                        }
                    }
                }
                else {
                    $content = $this->get_form_part($tab);
                }

                if ($content) {
                    $this->form_html .= html::tag('fieldset', null, html::tag('legend', null, Q($tab['name'])) . $content) ."\n";
                }
            }

            // Parse form template for skin-dependent stuff
            $this->rc->output->add_handler('calendarform', array($this, 'calendar_form_html'));
            return $this->rc->output->parse('calendar.kolabform', false, false);
        }
        catch (LibMelanie\Exceptions\Melanie2DatabaseException $ex) {
            melanie2_logs::get_instance()->log(melanie2_logs::ERROR, "[calendar] melanie2_driver::calendar_form() Melanie2DatabaseException");
            return false;
        }
        catch (\Exception $ex) {
            return false;
        }
        return false;
    }

    /**
     * Handler for template object
     */
    public function calendar_form_html()
    {
        return $this->form_html;
    }

    /**
     * Helper function used in calendar_form_content(). Creates a part of the form.
     */
    private function get_form_part($form)
    {
        $content = '';

        if (is_array($form['content']) && !empty($form['content'])) {
        	$table = new html_table(array('cols' => 2));
        	foreach ($form['content'] as $col => $colprop) {
        		$colprop['id'] = '_'.$col;
        		$label = !empty($colprop['label']) ? $colprop['label'] : rcube_label($col);

        		$table->add('title', sprintf('<label for="%s">%s</label>', $colprop['id'], Q($label)));
        		$table->add(null, $colprop['value']);
        	}
        	$content = $table->show();
        }
        else {
        	$content = $form['content'];
        }

        return $content;
    }


    /**
     * Handler to render ACL form for a calendar folder
     */
    public function calendar_acl()
    {
        $this->rc->output->add_handler('folderacl', array(new M2calendar($this->rc->user->get_username()), 'acl_form'));
        $this->rc->output->send('calendar.kolabacl');
    }
    /**
     * Handler to render ACL groups form for a calendar folder
     */
    public function calendar_acl_group()
    {
        $this->rc->output->add_handler('folderacl', array(new M2calendargroup($this->rc->user->get_username()), 'acl_form'));
        $this->rc->output->send('calendar.kolabacl');
    }
    /**
     * Converti l'id en identifiant utilisable par RC
     * @param string $id
     * @return string
     */
    private function _to_RC_id($id) {
        return str_replace('.', '_-P-_', $id);
    }
    /**
     * Converti l'id en identifiant utilisable par M2
     * @param string $id
     * @return string
     */
    private function _to_M2_id($id) {
        return str_replace('_-P-_', '.', $id);
    }
}