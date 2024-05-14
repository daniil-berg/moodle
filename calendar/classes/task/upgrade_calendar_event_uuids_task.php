<?php

namespace core_calendar\task;

use coding_exception;
use core\task\adhoc_task;
use dml_exception;

defined('MOODLE_INTERNAL') || die();


/**
 * Performs the necessary operations to fix the broken event UUIDs and clean up the resulting DB garbage.
 *
 * See issue MDL-... for details.
 *
 * This task is designed in place of an upgrade script and is intended to do the work only once.
 *
 * Check the documentation of {@see upgrade_calendar_event_uuids_task::clean_up_recursive_events} and
 * {@see upgrade_calendar_event_uuids_task::ensure_uuid_for_all_events} to see what exactly it does.
 *
 * @copyright 2024 Daniil Fajnberg
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class upgrade_calendar_event_uuids_task extends adhoc_task {
    /**
     * Constructor for convenience.
     *
     * If provided, the `$hostname` argument will be passed to  {@see clean_up_recursive_events} and
     * {@see ensure_uuid_for_all_events}. If `null` (default), the host name taken from `$CFG->wwwroot` will be used.
     *
     * @param string|null $hostname Host name to use when cleaning up and updating events.
     * @return self A new instance with its custom data field `hostname` set to the provided value.
     */
    public static function instance(?string $hostname = null): self {
        $task = new self();
        $task->set_custom_data((object) ['hostname' => $hostname]);
        return $task;
    }

    /**
     * @inheritDoc
     */
    public function get_name(): string {
        return 'Upgrade calendar event UUIDs & clean up recursive imports';
    }

    /**
     * @inheritDoc
     * @throws dml_exception
     */
    public function execute(): void {
        global $CFG;
        $hostname = $this->get_custom_data()->hostname ?? preg_replace('|https?://|', '', $CFG->wwwroot);
        mtrace("Running cleanup code for the host name '$hostname'.");
        $numdeleted = $this->clean_up_recursive_events($hostname);
        mtrace("Deleted $numdeleted recursively imported calendar events.");
        $numassigned = $this->ensure_uuid_for_all_events($hostname);
        mtrace("Assigned UUIDs to $numassigned calendar events without one.");
    }

    /**
     * Deletes all recursively imported events from subscriptions to calendars on the same Moodle instance.
     *
     * See issue MDL-... for details.
     *
     * A few notes on terminology:
     * Say we have an event `E` that has a `uuid` value matching the pattern `"(\d+)@$hostname"` (e.g. `"123@moodle.example.com"`).
     * If there is another event on the same Moodle instance with its `id` equal to the prefix in the UUID pattern of `E`
     * (e.g. `123`), we'll call that event the "parent" event of `E`. If that parent in turn has a parent event and so on, we'll
     * call any event in that chain (expect `E`) an "ancestor" of `E`.
     *
     * We want to delete an event `E`, if the following criteria are all satisfied:
     * 0) `E` has a `subscriptionid`.
     * 1) `E` has a parent event.
     * 2) The parent also has a `subscriptionid` and a parent event.
     * 3) `E` has an ancestor event `A` (which may or may not be its parent) that looks the same in all relevant properties
     *    (as determined by {@see events_look_the_same}).
     * 4) `E` and `A` belong to the same calendar (as determined by {@see events_are_from_the_same_calendar}).
     *
     * This picks up the self-referential subscription case, where a user subscribed to a calendar, to which events were added by
     * that same subscription. Assuming an "organically" created event `e0` was in that calendar, the subscription would create a
     * clone `e1` of `e0` during the first import, then another clone `e2` of `e1` during the second import and so on.
     * The algorithm here deletes all clones except for `e1`, provided neither of the clones were manually modified in any of their
     * relevant properties. The first clone created by the subscription will remain.
     * Since a subscription could imply a separate calendar on the user side, we want this to be possible and unaffected.
     * Only re-imported events within a subscription cycle should be deleted.
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
     * Provided neither of the clones were manually modified in any of their relevant properties, the algorithm here will only keep
     * the `sXs1`-clones (and `e0` of course) and delete all others.
     *
     * #### WARNING
     * For large `event` tables, this function can take a long time to run.
     * To avoid running out of memory (since e.g. event descriptions must be loaded, and they can be quite large), parent events
     * are queried on at a time. In the worst case, the number of DB lookup queries is in `O(n)`, with `n` being the number of rows
     * in the `event` table.
     * Deletion queries are issued in batches to be safe (not passing `IN`-clauses that are too large).
     *
     * @param string $hostname Host name of the current Moodle instance to match imported UUIDs on.
     * @return int Number of calendar events that have been deleted.
     * @throws dml_exception
     */
    public function clean_up_recursive_events(string $hostname): int {
        global $DB;
        // This will store the IDs of events to be deleted as keys (for faster lookup) and `null` values.
        $idstodelete = [];
        // We consider an event to be (almost certainly) imported from the same Moodle instance, if it belongs to any
        // subscription (i.e. it was imported via a URL) and its `uuid` string ends with the given hostname suffix.
        $sql = "SELECT *
                  FROM {event} AS e
                 WHERE e.subscriptionid IS NOT NULL
                       AND {$DB->sql_like('uuid', ':uuidpattern')}";
        $params = ['uuidpattern' => "%@$hostname"];
        // Count all the candidate events first.
        $countsql = preg_replace('/^SELECT \*/', 'SELECT COUNT(*)', $sql);
        $numtotal = $DB->count_records_sql($countsql, $params);
        mtrace("Found $numtotal events that were likely imported from the same Moodle instance.");
        // If an event has a parent in the same Moodle database, but that parent was not imported from the same Moodle
        // instance, we do not want to delete the event. Therefore, we can reuse the SQL query from above to find parents.
        $parentsql = $sql . ' AND id = :id';
        // The following closure returns the parent of an event from the database.
        // If the event's UUID does not match the expected pattern or no matching record is found, `false` is returned.
        $getparentevent = function (object $event) use ($DB, $parentsql, $params): mixed {
            if (!$parentid = self::get_parent_event_id($event->uuid)) { return false; }
            return $DB->get_record_sql($parentsql, $params + ['id' => $parentid]);
        };
        // Iterate over all events imported from the same Moodle instance.
        foreach ($DB->get_recordset_sql($sql, $params) as $event) {
            // Try to find a parent event that satisfies our conditions.
            $ancestorevent = $getparentevent($event);
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
                $ancestorevent = $getparentevent($ancestorevent);
            }
        }
        self::batch_delete_events(array_keys($idstodelete));
        return count($idstodelete);
    }

    /**
     * Extracts the supposed parent event's ID from the UUID of an event.
     *
     * If the UUID does not match the expected pattern, `0` is returned.
     *
     * @param string $uuid UUID string of an event.
     * @return int ID of the parent event or 0.
     */
    protected static function get_parent_event_id(string $uuid): int {
        $uuidparts = explode('@', $uuid, 2);
        return count($uuidparts) === 2 ? (int) $uuidparts[0] : 0;
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
            if (($obj1->$property ?? null) !== ($obj2->$property ?? null)) { return false; }
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
        $foreignkeys = ['categorid', 'courseid', 'groupid', 'userid'];
        return $event1->subscriptionid === $event2->subscriptionid || self::same_properties($foreignkeys, $event1, $event2);
    }

    /**
     * Deletes events from the database in batches.
     *
     * Issues one `DELETE ... WHERE id IN ...` query for every batch.
     *
     * @param string[] $idstodelete Array of IDs of events to delete.
     * @param int $batchsize Maximum number of ids to put in a single `DELETE` query.
     * @throws dml_exception
     */
    protected static function batch_delete_events(array $idstodelete, int $batchsize = 1000): void {
        global $DB;
        foreach (array_chunk($idstodelete, $batchsize) as $ids) {
            try {
                [$insql, $inparams] = $DB->get_in_or_equal($ids);
            } catch (coding_exception) {
                // This should be unreachable.
                continue;
            }
            $DB->delete_records_select('event', "id $insql", $inparams);
        }
    }

    /**
     * Assigns a `uuid` to all events that have an empty string for it.
     *
     * Uses the former `<id>@<hostname>` logic to have backwards compatibility.
     *
     * @param string $hostname Host name of the current Moodle instance to use as UUID suffix.
     * @return int Number of calendar events that have been updated.
     * @throws dml_exception
     */
    public function ensure_uuid_for_all_events(string $hostname): int {
        global $DB;
        $count = $DB->count_records('event', ['uuid' => '']);
        $sql = "UPDATE {event}
                   SET uuid = {$DB->sql_concat("id", "'@'", ":hostname")}
                 WHERE uuid = ''";
        $DB->execute($sql, ['hostname' => $hostname]);
        return $count;
    }
}
