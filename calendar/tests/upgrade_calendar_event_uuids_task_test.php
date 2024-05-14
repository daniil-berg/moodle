<?php

namespace core_calendar;

use advanced_testcase;
use coding_exception;
use core_calendar\task\upgrade_calendar_event_uuids_task;
use dml_exception;

defined('MOODLE_INTERNAL') || die();


/**
 * Tests for the {@see upgrade_calendar_event_uuids_task} class.
 *
 * @package    core_calendar
 * @copyright  2024 Daniil Fajnberg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class upgrade_calendar_event_uuids_task_test extends advanced_testcase {
    /**
     * Tests that executing the task calls the expected cleanup methods and disables itself.
     *
     * @covers upgrade_calendar_event_uuids_task::execute
     * @throws dml_exception
     */
    public function test_execute(): void {
        global $CFG;

        // We expect the `wwwroot` host name to be used by default.
        $hostname = preg_replace('|https?://|', '', $CFG->wwwroot);
        $mock_task = $this->getMockBuilder(upgrade_calendar_event_uuids_task::class)
                          ->onlyMethods(['clean_up_recursive_events', 'ensure_uuid_for_all_events'])
                          ->getMock();
        $mock_task->expects($this->once())
                  ->method('clean_up_recursive_events')
                  ->with($hostname)
                  ->willReturn(123);
        $mock_task->expects($this->once())
                  ->method('ensure_uuid_for_all_events')
                  ->with($hostname)
                  ->willReturn(456);

        ob_start();
        $mock_task->execute();
        $output = ob_get_clean();

        $this->assertStringContainsString('Deleted 123 recursively imported calendar events', $output);
        $this->assertStringContainsString('Assigned UUIDs to 456 calendar events', $output);
    }

    /**
     * Tests that clean up of calendar events is done as expected.
     *
     * @covers upgrade_calendar_event_uuids_task::clean_up_recursive_events
     * @throws coding_exception
     * @throws dml_exception
     */
    public function test_clean_up_recursive_events(): void {
        global $CFG, $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();

        $task = new upgrade_calendar_event_uuids_task();

        $hostname = preg_replace('|https?://|', '', $CFG->wwwroot);

        // Generates a UUIDs for an "exported" event using the old logic.
        $generateuuid = function (object $event) use ($hostname): string {
            return "$event->id@$hostname";
        };
        // Creates an event clone deriving the parent's UUID using the old logic.
        $createclone = function (object $parentevent, array $data) use ($generateuuid, $generator): object {
            return $generator->create_event($data + ['uuid' => $generateuuid($parentevent)]);
        };
        // Gets the UUID of an event from the database.
        $fetcheventuuid = function (int $eventid) use ($DB): string {
            return $DB->get_record('event', ['id' => $eventid], 'uuid', MUST_EXIST)->uuid;
        };

        ////////////////////////////////////
        // Single-subscription test-case: //

        // Create a user and a calendar subscription.
        $user1 = $generator->create_user();
        $sub1 = $generator->create_calendar_event_subscription();
        // Create two "root" events for that user that will be cloned by a recursive subscription.
        $eventfoo0 = $generator->create_event(['userid' => $user1->id, 'uuid' => 'foo']);
        $eventbar0 = $generator->create_event(['userid' => $user1->id, 'uuid' => 'bar']);

        // Simulate importing the user's own calendar three times.
        $eventdata = ['userid' => $user1->id, 'subscriptionid' => $sub1->id];
        $eventfoo1 = $createclone($eventfoo0, $eventdata);
        $eventbar1 = $createclone($eventbar0, $eventdata);
        $eventfoo2 = $createclone($eventfoo1, $eventdata);
        $eventbar2 = $createclone($eventbar1, $eventdata);
        $eventfoo3 = $createclone($eventfoo2, $eventdata);
        $eventbar3 = $createclone($eventbar2, $eventdata);

        // Perform the clean-up.
        ob_start();
        $numdeleted = $task->clean_up_recursive_events($hostname);
        $output = ob_get_clean();

        // Check that the expected number of events are reported as deleted.
        $this->assertStringContainsString('Found 6 events', $output);
        $this->assertEquals(4, $numdeleted);

        // Check that the events we expect to have been deleted are no longer there.
        $this->assertFalse($DB->get_record('event', ['id' => $eventfoo2->id]));
        $this->assertFalse($DB->get_record('event', ['id' => $eventbar2->id]));
        $this->assertFalse($DB->get_record('event', ['id' => $eventfoo3->id]));
        $this->assertFalse($DB->get_record('event', ['id' => $eventbar3->id]));

        // Check that the first clones of each root event still exist.
        $this->assertEquals($generateuuid($eventfoo0), $fetcheventuuid($eventfoo1->id));
        $this->assertEquals($generateuuid($eventbar0), $fetcheventuuid($eventbar1->id));

        $DB->delete_records('event');

        ///////////////////////////////////
        // Multi-subscription test-case: //

        // Create three new users and a subscription for each of them.
        $user1 = $generator->create_user();
        $user2 = $generator->create_user();
        $user3 = $generator->create_user();
        $sub1 = $generator->create_calendar_event_subscription();
        $sub2 = $generator->create_calendar_event_subscription();
        $sub3 = $generator->create_calendar_event_subscription();

        // Simulate a 3-node circular import:
        // - `sub1` imports from calendar populated by `sub3`.
        // - `sub2` imports from calendar populated by `sub1`.
        // - `sub3` imports from calendar populated by `sub2`.

        $sub1data = ['userid' => $user1->id, 'subscriptionid' => $sub1->id];
        $sub2data = ['userid' => $user2->id, 'subscriptionid' => $sub2->id];
        $sub3data = ['userid' => $user3->id, 'subscriptionid' => $sub3->id];

        // Create a non-imported root event in each calendar.
        $eventx0 = $generator->create_event(['userid' => $user1->id, 'uuid' => 'x']);
        $eventy0 = $generator->create_event(['userid' => $user2->id, 'uuid' => 'y']);
        $eventz0 = $generator->create_event(['userid' => $user3->id, 'uuid' => 'z']);

        // Run three cycles of imports.

        // `sub1` runs for the first time and receives its first clone of event `z`.
        $sub1eventz1 = $createclone($eventz0, $sub1data);

        // `sub2` runs for the first time and receives its first clone of events `x` (root) and `z` (clone).
        $sub2eventx1 = $createclone($eventx0, $sub2data);
        $sub2eventz1 = $createclone($sub1eventz1, $sub2data);

        // `sub3` runs for the first time and receives its first clone of events `y` (root) and `x` (clone),
        // as well as its first clone of "its own" event `z`.
        $sub3eventy1 = $createclone($eventy0, $sub3data);
        $sub3eventx1 = $createclone($sub2eventx1, $sub3data);
        $sub3eventz1 = $createclone($sub2eventz1, $sub3data);

        // `sub1` runs for the second time and receives its first clone of "its own" event `x`,
        // as well as its first clone of `y` and its second clone of `z`.
        $sub1eventy1 = $createclone($sub3eventy1, $sub1data);
        $sub1eventx1 = $createclone($sub3eventx1, $sub1data);
        $sub1eventz2 = $createclone($sub3eventz1, $sub1data);

        // `sub2` runs for the second time and receives its first clone of "its own" event `y`,
        // as well as its second clones of `x` and `z`.
        $sub2eventy1 = $createclone($sub1eventy1, $sub2data);
        $sub2eventx2 = $createclone($sub1eventx1, $sub2data);
        $sub2eventz2 = $createclone($sub1eventz2, $sub2data);

        // `sub3` runs for the second time.
        $sub3eventy2 = $createclone($sub2eventy1, $sub3data);
        $sub3eventx2 = $createclone($sub2eventx2, $sub3data);
        $sub3eventz2 = $createclone($sub2eventz2, $sub3data);

        // `sub1` runs for the third time.
        $sub1eventy2 = $createclone($sub3eventy2, $sub1data);
        $sub1eventx2 = $createclone($sub3eventx2, $sub1data);
        $sub1eventz3 = $createclone($sub3eventz2, $sub1data);

        // `sub2` runs for the third time.
        $sub2eventy2 = $createclone($sub1eventy2, $sub2data);
        $sub2eventx3 = $createclone($sub1eventx2, $sub2data);
        $sub2eventz3 = $createclone($sub1eventz3, $sub2data);

        // `sub3` runs for the third time.
        $sub3eventy3 = $createclone($sub2eventy2, $sub3data);
        $sub3eventx3 = $createclone($sub2eventx3, $sub3data);
        $sub3eventz3 = $createclone($sub2eventz3, $sub3data);

        // Check the total number of events now.
        $eventstotal = $DB->count_records('event');
        // Do a sanity check.
        $this->assertEquals(27, $eventstotal);

        // We expect all events that are not first-clones for a subscription to be deleted:
        $shouldbedeleted = [
                $sub1eventz2,
                $sub2eventx2,
                $sub2eventz2,
                $sub3eventy2,
                $sub3eventx2,
                $sub3eventz2,
                $sub1eventy2,
                $sub1eventx2,
                $sub1eventz3,
                $sub2eventy2,
                $sub2eventx3,
                $sub2eventz3,
                $sub3eventy3,
                $sub3eventx3,
                $sub3eventz3,
        ];
        // We obviously expect the root events and the first-clones for each subscription to remain:
        // (This means 3 root events and 9 clones.)
        $shouldremain =  [
            // Root:
                $eventx0,
                $eventy0,
                $eventz0,
            // First `sub1` run:
                $sub1eventz1,
            // First `sub2` run:
                $sub2eventx1,
                $sub2eventz1,
            // First `sub3` run:
                $sub3eventy1,
                $sub3eventx1,
                $sub3eventz1,
            // Second `sub1` run:
                $sub1eventy1,
                $sub1eventx1,
            // Second `sub2` run:
                $sub2eventy1,
        ];
        // Do a few sanity checks.
        $this->assertCount(15, $shouldbedeleted);
        $this->assertCount(12, $shouldremain);

        // Run the clean-up.
        ob_start();
        $numdeleted = $task->clean_up_recursive_events($hostname);
        $output = ob_get_clean();

        // Check the counts.
        $this->assertStringContainsString('Found 24 events', $output);
        $this->assertEquals(15, $numdeleted);
        $this->assertEquals(12, $DB->count_records('event'));

        // Check that the exact events we expect are really no longer there.
        foreach ($shouldbedeleted as $event) {
            $this->assertFalse($DB->get_record('event', ['id' => $event->id]));
        }

        // Manually check that the exact events we expect are still in the DB (and their UUIDs are as expected).
        $this->assertEquals('x', $fetcheventuuid($eventx0->id));
        $this->assertEquals('y', $fetcheventuuid($eventy0->id));
        $this->assertEquals('z', $fetcheventuuid($eventz0->id));
        $this->assertEquals($generateuuid($eventz0), $fetcheventuuid($sub1eventz1->id));
        $this->assertEquals($generateuuid($eventx0), $fetcheventuuid($sub2eventx1->id));
        $this->assertEquals($generateuuid($sub1eventz1), $fetcheventuuid($sub2eventz1->id));
        $this->assertEquals($generateuuid($eventy0), $fetcheventuuid($sub3eventy1->id));
        $this->assertEquals($generateuuid($sub2eventx1), $fetcheventuuid($sub3eventx1->id));
        $this->assertEquals($generateuuid($sub2eventz1), $fetcheventuuid($sub3eventz1->id));
        $this->assertEquals($generateuuid($sub3eventy1), $fetcheventuuid($sub1eventy1->id));
        $this->assertEquals($generateuuid($sub3eventx1), $fetcheventuuid($sub1eventx1->id));
        $this->assertEquals($generateuuid($sub1eventy1), $fetcheventuuid($sub2eventy1->id));
    }

    /**
     * Tests that UUIDs are assigned to all calendar events with empty ones.
     *
     * @covers upgrade_calendar_event_uuids_task::ensure_uuid_for_all_events
     * @throws coding_exception
     * @throws dml_exception
     */
    public function test_ensure_uuid_for_all_events(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();

        $task = new upgrade_calendar_event_uuids_task();

        $user1 = $generator->create_user();
        $user2 = $generator->create_user();
        $e1 = $generator->create_event(['userid' => $user1->id, 'uuidgenerator' => function() {return '';}]);
        $e2 = $generator->create_event(['userid' => $user1->id, 'uuid' => 'abc']);
        $e3 = $generator->create_event(['userid' => $user2->id, 'uuidgenerator' => function() {return '';}]);
        $this->assertEquals("", $e1->uuid);
        $this->assertEquals("abc", $e2->uuid);
        $this->assertEquals("", $e3->uuid);

        $hostname = "foo.bar";
        $num = $task->ensure_uuid_for_all_events($hostname);
        $this->assertEquals(2, $num);

        $e1 = $DB->get_record('event', ['id' => $e1->id]);
        $e2 = $DB->get_record('event', ['id' => $e2->id]);
        $e3 = $DB->get_record('event', ['id' => $e3->id]);

        $this->assertEquals("$e1->id@$hostname", $e1->uuid);
        $this->assertEquals("abc", $e2->uuid);
        $this->assertEquals("$e3->id@$hostname", $e3->uuid);
    }
}
