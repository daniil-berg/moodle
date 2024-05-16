<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Definition of the {@see \core_calendar\task\upgrade_calendar_event_uuids_task} class.
 *
 * @package   core_calendar\task
 * @copyright 2025 Daniil Fajnberg <d.fajnberg@tu-berlin.de>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace core_calendar\task;

use coding_exception;
use core\output\terminal_progress_bar;
use core\task\adhoc_task;
use dml_exception;

/**
 * Performs the necessary operations to fix the broken event UUIDs and clean up the resulting DB garbage.
 *
 * See issue {@see https://tracker.moodle.org/browse/MDL-...} for details.
 *
 * This task is designed in place of an upgrade script and is intended to do the work only once.
 *
 * Check the documentation of {@see upgrade_calendar_event_uuids_task::clean_up_recursive_events} and
 * {@see upgrade_calendar_event_uuids_task::ensure_uuid_for_all_events} to see what exactly it does.
 *
 * @copyright 2025 Daniil Fajnberg <d.fajnberg@tu-berlin.de>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class upgrade_calendar_event_uuids_task extends adhoc_task {
    /**
     * @var string Web facing host name of the Moodle instance to use for matching supposedly self-imported UUIDs on. We store it as
     *             a property to avoid passing it around every method or being forced to deserialize the custom data every time.
     *             (Distinct from the {@see \core\task\task_base::hostname} property.)
     */
    protected string $wwwhostname;

    /**
     * Constructor for convenience.
     *
     * If provided, the `$wwwhostname` argument will be passed to  {@see clean_up_recursive_events} and
     * {@see ensure_uuid_for_all_events}. If `null` (default), the host name taken from `$CFG->wwwroot` will be used.
     *
     * @param string|null $wwwhostname Web facing host name of the Moodle instance to use for matching supposedly self-imported
     *                                 UUIDs when cleaning up and updating events.
     * @return self A new instance with its custom data field `wwwhostname` set to the provided value.
     */
    public static function instance(string|null $wwwhostname = null): self {
        $task = new self();
        $task->set_wwwhostname($wwwhostname);
        return $task;
    }

    /**
     * Sets the {@see wwwhostname} property and custom data value.
     *
     * If `null` is passed (default), the method derives the host name portion from the `$CFG->wwwroot` setting.
     *
     * @param string|null $wwwhostname The host name to set.
     */
    public function set_wwwhostname(string|null $wwwhostname = null): void {
        global $CFG;
        if (is_null($wwwhostname)) {
            $wwwhostname = preg_replace('|https?://|', '', $CFG->wwwroot);
        }
        $this->wwwhostname = $wwwhostname;
        $this->set_custom_data(['wwwhostname' => $wwwhostname]);
    }

    public function get_name(): string {
        return 'Upgrade calendar event UUIDs & clean up recursive imports';
    }

    /**
     * {@inheritDoc}
     *
     * @throws coding_exception
     * @throws dml_exception
     */
    public function execute(): void {
        // Calling the setter here ensures the property is set consistently, even if `set_wwwhostname` has not been called yet.
        // The task may e.g. have been re-constructed with just the serialized custom data to be retried after failing.
        $this->set_wwwhostname($this->get_custom_data()->wwwhostname);
        mtrace("Running cleanup code for the host name '$this->wwwhostname'.");
        $numdeleted = $this->clean_up_recursive_events();
        mtrace("Deleted $numdeleted recursively imported calendar events.");
        $numassigned = $this->ensure_uuid_for_all_events();
        mtrace("Assigned UUIDs to $numassigned calendar events without one.");
    }

    /**
     * Deletes all recursively imported events from subscriptions to calendars on the same Moodle instance.
     *
     * See issue {@see https://tracker.moodle.org/browse/MDL-...} for details.
     *
     * ## Terminology
     *
     * Say we have an event `E` that has a `uuid` value, which starts with an integer followed by the `@` sign followed by the
     * {@see wwwhostname} of the Moodle instance (e.g. `"123@moodle.example.com"`).
     * If there is another event on the same Moodle instance with its `id` equal to the integer in the UUID pattern of `E`
     * (e.g. `123`), we'll call that event the "parent" event of `E`. If that parent in turn has a parent event and so on, we shall
     * call any event in that chain (expect `E`) an "ancestor" of `E`.
     *
     * ## Which events should be deleted?
     *
     * We want to delete an event `E`, if the following criteria are all satisfied:
     * 0) `E` has a `subscriptionid`.
     * 1) `E` has a parent event.
     * 2) The parent also has a `subscriptionid` and a parent event.
     * 3) `E` has an ancestor event `A` (which may or may not be its parent) that looks the same in all relevant properties
     *    (as determined by the {@see events_look_the_same} method).
     * 4) `E` and `A` belong to the same calendar (as determined by the {@see events_are_from_the_same_calendar} method).
     *
     * ## Recursive/Self-importing calendar
     *
     * This picks up the self-referential subscription case, where a user subscribed to a calendar, to which events were added by
     * that same subscription. Assuming an "organically" created event `e0` was in that calendar, the subscription would create a
     * clone `e1` of `e0` during the first import, then another clone `e2` of `e1` during the second import and so on.
     * The algorithm here deletes all clones except for `e1`, provided neither of the clones were manually modified in any of their
     * relevant properties. The first clone created by the subscription will remain.
     * Since a subscription could imply a separate calendar on the user side, we want this to be possible and unaffected.
     * Only re-imported events within a subscription cycle should be deleted.
     *
     * ## Subscription cycles (multiple calendars)
     *
     * This also covers the case where _two or more_ distinct subscriptions have been importing events from one another.
     * Assume the following three-node recursive import setup.
     * Subscription `s1` populates a calendar belonging to user `u1`, which is queried by a different subscription `s2`, which
     * populates a calendar of user `u2` queried by yet another subscription `s3`, which populates a calendar belonging to
     * user `u3`, and that is in turn queried by `s1`.
     * Assume an organically created event `e0` in the calendar of user `u3`.
     * - `s1` creates a clone `s1e1` of `e0` in its calendar during its first import.
     * - `s2` creates a clone `s2e1` of `s1e1` in its calendar during its first import.
     * - `s3` creates a clone `s3e1` of `s2e1` in its calendar during its first import.
     * - `s1` creates a clone `s1e2` of `s3e1` in its calendar during its second import.
     * - `s2` creates a clone `s2e2` of `s1e2` in its calendar during its second import.
     * - `s3` creates a clone `s3e2` of `s2e2` in its calendar during its second import.
     * - And so on.
     *
     * Provided neither of the clones were manually modified in any of their relevant properties, the algorithm here will only keep
     * the `sXs1`-clones (and `e0` of course) and delete all others.
     *
     * ## Time complexity and memory considerations
     *
     * For large `event` tables, this function can take a long time to run.
     * To avoid running out of memory (since e.g. event descriptions must be loaded, and they can be quite large), parent events
     * are queried one at a time. In the worst case, the number of DB lookup queries is in `O(n)`, with `n` being the number of rows
     * in the `event` table.
     * Deletion queries are issued in batches to be safe (to not generate `IN`-clauses that are too large).
     *
     * @return int Number of calendar events that have been deleted.
     * @throws coding_exception
     * @throws dml_exception
     */
    public function clean_up_recursive_events(): int {
        global $DB;
        // This will store the IDs of events to be deleted as keys (for faster lookup) and `null` values.
        $idstodelete = [];
        // Count all the candidate events first.
        $numtotal = $DB->count_records_sql(...$this->build_event_query(selectcount: true));
        mtrace("Found $numtotal events that were likely imported from the same Moodle instance.");
        if ($numtotal === 0) {
            return 0;
        }
        // Iterate over all events imported from the same Moodle instance.
        $progress = new terminal_progress_bar(
            stepstotal: $numtotal,
            stepsbetweenoutputs: (int) ceil($numtotal/100),
            updatestepsnow: $i = 0,
        );
        foreach ($DB->get_recordset_sql(...$this->build_event_query()) as $event) {
            // Try to find a parent event that satisfies our conditions.
            $ancestorevent = self::get_parent_event($event);
            while ($ancestorevent) {
                // If we already decided to delete that ancestor, we can definitely delete the event.
                if (array_key_exists($ancestorevent->id, $idstodelete)) {
                    $idstodelete[$event->id] = null;
                    break;
                }
                // If the ancestor looks different, we stay on the safe side and do not delete.
                if (!self::events_look_the_same($event, $ancestorevent)) {
                    break;
                }
                // If the ancestor looks the same and is from the same calendar, we can safely delete the event.
                if (self::events_are_from_the_same_calendar($event, $ancestorevent)) {
                    $idstodelete[$event->id] = null;
                    break;
                }
                // The ancestor looks the same, but is not from the same calendar.
                // We might still have an import cycle. (Subscriptions importing each other.) Move up the ancestry branch.
                $ancestorevent = self::get_parent_event($ancestorevent);
            }
            $progress->update(++$i);
        }
        self::batch_delete_events(array_keys($idstodelete));
        return count($idstodelete);
    }

    /**
     * Builds an SQL query to fetch events that have likely been imported from the same Moodle instance.
     *
     * We consider an event to be (almost certainly) imported from the same Moodle instance, if it belongs to any
     * subscription (i.e. it was imported via a URL) and its `uuid` string ends with the {@see wwwhostname} suffix.
     *
     * @param int|null $id If passed, the query will only return an event with a matching `id`. (Used to find parent events.)
     * @param bool $selectcount Whether to select the count of events only (`true`) or all event fields (`false`, the default).
     * @return array An array containing the generated SQL query string and its associated parameters.
     */
    protected function build_event_query(int|null $id = null, bool $selectcount = false): array {
        global $DB;
        $fields = $selectcount ? 'COUNT(*)' : '*';
        $sql = "SELECT $fields
                  FROM {event} AS e
                 WHERE e.subscriptionid IS NOT NULL
                       AND {$DB->sql_like('uuid', ':uuidpattern')}";
        $params = ['uuidpattern' => "%@$this->wwwhostname"];
        if ($id) {
            $sql .= ' AND id = :id';
            $params['id'] = $id;
        }
        return [$sql, $params];
    }

    /**
     * Returns the parent of an event from the database.
     *
     * If the event's UUID does not match the expected pattern or no matching record is found, `false` is returned.
     *
     * If an event has a parent in the same Moodle database, but that parent was **not** imported from the same Moodle instance, we
     * do not want to delete the event. Therefore, we use the {@see build_event_query} method to find parents.
     *
     * @param object $event Event for which to fetch the parent.
     * @return object|false The parent event object, if one matching our criteria was found; `false`, if the `$event` has no parent
     *                      or that parent was not imported from the {@see wwwhostname} Moodle instance.
     * @throws dml_exception
     */
    protected function get_parent_event(object $event): object|false {
        global $DB;
        $uuidparts = explode('@', $event->uuid, 2);
        if (count($uuidparts) !== 2) {
            return false;
        }
        return $DB->get_record_sql(...$this->build_event_query(id: $uuidparts[0]));
    }

    /**
     * Checks whether two events look the same in their relevant properties.
     *
     * The relevant properties are `name`, `description`, `format`, `timestart`, `timeduration`, `priority`, and `location`.
     *
     * @param object $event1 First event.
     * @param object $event2 Second event.
     * @return bool `true` if `event1` and `event2` are exactly equal in their relevant properties; `false` otherwise.
     */
    public static function events_look_the_same(object $event1, object $event2): bool {
        $eventproperties = ['name', 'description', 'format', 'timestart', 'timeduration', 'priority', 'location'];
        return self::same_properties($eventproperties, $event1, $event2);
    }

    /**
     * Helper function to check if two objects are the same in the specified properties.
     *
     * @param string[] $properties List of property names to compare.
     * @param object $obj1 First object.
     * @param object $obj2 Second object.
     * @return bool `true` if `obj1` and `obj2` are exactly equal in every property from `properties`; `false` otherwise.
     */
    protected static function same_properties(array $properties, object $obj1, object $obj2): bool {
        foreach ($properties as $property) {
            if (($obj1->$property ?? null) !== ($obj2->$property ?? null)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Checks whether two events are from the same calendar.
     *
     * This is the case when they both have the same `subscriptionid` or if they are exactly equal in the properties
     * `categorid`, `courseid`, `groupid`, and `userid`.
     *
     * @param object $event1 First event.
     * @param object $event2 Second event.
     * @return bool `true` if `event1` and `event2` are from the same calendar; `false` otherwise.
     */
    public static function events_are_from_the_same_calendar(object $event1, object $event2): bool {
        $foreignkeys = ['categoryid', 'courseid', 'groupid', 'userid'];
        return $event1->subscriptionid === $event2->subscriptionid || self::same_properties($foreignkeys, $event1, $event2);
    }

    /**
     * Deletes events from the database in batches.
     *
     * Issues one `DELETE ... WHERE id IN ...` query for every batch.
     *
     * @param string[] $idstodelete Array of IDs of events to delete.
     * @param int $batchsize Maximum number of ids to put in a single `DELETE` query.
     * @throws coding_exception
     * @throws dml_exception
     */
    protected static function batch_delete_events(array $idstodelete, int $batchsize = 1000): void {
        global $DB;
        foreach (array_chunk($idstodelete, $batchsize) as $ids) {
            [$insql, $inparams] = $DB->get_in_or_equal($ids);
            $DB->delete_records_select('event', "id $insql", $inparams);
        }
    }

    /**
     * Assigns a `uuid` to all events that have an empty string for it.
     *
     * Uses the former `<id>@<wwwhostname>` logic to have backwards compatibility.
     *
     * @return int Number of calendar events that have been updated.
     * @throws dml_exception
     */
    public function ensure_uuid_for_all_events(): int {
        global $DB;
        $count = $DB->count_records('event', ['uuid' => '']);
        $sql = "UPDATE {event}
                   SET uuid = {$DB->sql_concat("id", "'@'", ":wwwhostname")}
                 WHERE uuid = ''";
        $DB->execute($sql, ['wwwhostname' => $this->wwwhostname]);
        return $count;
    }
}
