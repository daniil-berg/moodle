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
 * Definition of the {@see \core_calendar\task\upgrade_calendar_event_uuids_task_test} class.
 *
 * @package   core_calendar\task
 * @copyright 2025 Daniil Fajnberg <d.fajnberg@tu-berlin.de>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * {@noinspection PhpIllegalPsrClassPathInspection}
 */

namespace core_calendar\task;

use advanced_testcase;
use coding_exception;
use dml_exception;
use function DeepCopy\deep_copy;

/**
 * Tests for the {@see upgrade_calendar_event_uuids_task} class.
 *
 * @coversDefaultClass upgrade_calendar_event_uuids_task
 *
 * @copyright 2025 Daniil Fajnberg <d.fajnberg@tu-berlin.de>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class upgrade_calendar_event_uuids_task_test extends advanced_testcase {
    /**
     * @var string Host name of the current Moodle instance to match imported UUIDs on.
     */
    protected string $wwwhostname;

    /**
     * Sets the {@see wwwhostname} property to the `$CFG->wwwroot` value with the scheme stripped.
     */
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        $this->wwwhostname = preg_replace('|https?://|', '', $CFG->wwwroot);
    }

    /**
     * Tests that executing the task calls the expected cleanup methods.
     *
     * @covers ::execute
     * @throws coding_exception
     * @throws dml_exception
     */
    public function test_execute(): void {
        $mock_task = $this->getMockBuilder(upgrade_calendar_event_uuids_task::class)
                          ->onlyMethods(['clean_up_recursive_events', 'ensure_uuid_for_all_events'])
                          ->getMock();
        $mock_task->expects($this->once())
                  ->method('clean_up_recursive_events')
                  ->with()
                  ->willReturn(123);
        $mock_task->expects($this->once())
                  ->method('ensure_uuid_for_all_events')
                  ->with()
                  ->willReturn(456);
        $mock_task->set_wwwhostname($this->wwwhostname);

        ob_start();
        $mock_task->execute();
        $output = ob_get_clean();

        $this->assertStringContainsString('Deleted 123 recursively imported calendar events', $output);
        $this->assertStringContainsString('Assigned UUIDs to 456 calendar events', $output);
    }

    /**
     * Tests that clean up of calendar events is done as expected, when a calendar imported from itself.
     *
     * @covers ::clean_up_recursive_events
     * @throws coding_exception
     * @throws dml_exception
     */
    public function test_clean_up_recursive_events_self_subscription(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();

        $task = new upgrade_calendar_event_uuids_task();
        $task->set_wwwhostname($this->wwwhostname);

        // Single-subscription test-case.

        // Create a user and a calendar subscription.
        $user1 = $generator->create_user();
        $sub1 = $generator->create_calendar_event_subscription();
        // Create two "root" events for that user that will be cloned by a recursive subscription.
        $eventfoo0 = $generator->create_event(['userid' => $user1->id, 'uuid' => 'foo']);
        $eventbar0 = $generator->create_event(['userid' => $user1->id, 'uuid' => 'bar']);

        // Simulate importing the user's own calendar three times.
        $eventdata = ['userid' => $user1->id, 'subscriptionid' => $sub1->id];
        $eventfoo1 = $this->event_clone($eventfoo0, $eventdata);
        $eventbar1 = $this->event_clone($eventbar0, $eventdata);
        $eventfoo2 = $this->event_clone($eventfoo1, $eventdata);
        $eventbar2 = $this->event_clone($eventbar1, $eventdata);
        $eventfoo3 = $this->event_clone($eventfoo2, $eventdata);
        $eventbar3 = $this->event_clone($eventbar2, $eventdata);

        // Perform the clean-up.
        ob_start();
        $numdeleted = $task->clean_up_recursive_events();
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
        $this->assertEquals($this->generate_uuid($eventfoo0), $this->fetch_event_uuid($eventfoo1->id));
        $this->assertEquals($this->generate_uuid($eventbar0), $this->fetch_event_uuid($eventbar1->id));
    }

    /**
     * Tests that clean up of calendar events is done as expected, when multiple calendars imported from one another in a loop.
     *
     * @covers ::clean_up_recursive_events
     * @throws coding_exception
     * @throws dml_exception
     */
    public function test_clean_up_recursive_events_multi_subscription_loop(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();

        $task = new upgrade_calendar_event_uuids_task();
        $task->set_wwwhostname($this->wwwhostname);

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
        $sub1eventz1 = $this->event_clone($eventz0, $sub1data);

        // `sub2` runs for the first time and receives its first clone of events `x` (root) and `z` (clone).
        $sub2eventx1 = $this->event_clone($eventx0, $sub2data);
        $sub2eventz1 = $this->event_clone($sub1eventz1, $sub2data);

        // `sub3` runs for the first time and receives its first clone of events `y` (root) and `x` (clone),
        // as well as its first clone of "its own" event `z`.
        $sub3eventy1 = $this->event_clone($eventy0, $sub3data);
        $sub3eventx1 = $this->event_clone($sub2eventx1, $sub3data);
        $sub3eventz1 = $this->event_clone($sub2eventz1, $sub3data);

        // `sub1` runs for the second time and receives its first clone of "its own" event `x`,
        // as well as its first clone of `y` and its second clone of `z`.
        $sub1eventy1 = $this->event_clone($sub3eventy1, $sub1data);
        $sub1eventx1 = $this->event_clone($sub3eventx1, $sub1data);
        $sub1eventz2 = $this->event_clone($sub3eventz1, $sub1data);

        // `sub2` runs for the second time and receives its first clone of "its own" event `y`,
        // as well as its second clones of `x` and `z`.
        $sub2eventy1 = $this->event_clone($sub1eventy1, $sub2data);
        $sub2eventx2 = $this->event_clone($sub1eventx1, $sub2data);
        $sub2eventz2 = $this->event_clone($sub1eventz2, $sub2data);

        // `sub3` runs for the second time.
        $sub3eventy2 = $this->event_clone($sub2eventy1, $sub3data);
        $sub3eventx2 = $this->event_clone($sub2eventx2, $sub3data);
        $sub3eventz2 = $this->event_clone($sub2eventz2, $sub3data);

        // `sub1` runs for the third time.
        $sub1eventy2 = $this->event_clone($sub3eventy2, $sub1data);
        $sub1eventx2 = $this->event_clone($sub3eventx2, $sub1data);
        $sub1eventz3 = $this->event_clone($sub3eventz2, $sub1data);

        // `sub2` runs for the third time.
        $sub2eventy2 = $this->event_clone($sub1eventy2, $sub2data);
        $sub2eventx3 = $this->event_clone($sub1eventx2, $sub2data);
        $sub2eventz3 = $this->event_clone($sub1eventz3, $sub2data);

        // `sub3` runs for the third time.
        $sub3eventy3 = $this->event_clone($sub2eventy2, $sub3data);
        $sub3eventx3 = $this->event_clone($sub2eventx3, $sub3data);
        $sub3eventz3 = $this->event_clone($sub2eventz3, $sub3data);

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
        $numdeleted = $task->clean_up_recursive_events();
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
        $this->assertEquals('x', $this->fetch_event_uuid($eventx0->id));
        $this->assertEquals('y', $this->fetch_event_uuid($eventy0->id));
        $this->assertEquals('z', $this->fetch_event_uuid($eventz0->id));
        $this->assertEquals($this->generate_uuid($eventz0), $this->fetch_event_uuid($sub1eventz1->id));
        $this->assertEquals($this->generate_uuid($eventx0), $this->fetch_event_uuid($sub2eventx1->id));
        $this->assertEquals($this->generate_uuid($sub1eventz1), $this->fetch_event_uuid($sub2eventz1->id));
        $this->assertEquals($this->generate_uuid($eventy0), $this->fetch_event_uuid($sub3eventy1->id));
        $this->assertEquals($this->generate_uuid($sub2eventx1), $this->fetch_event_uuid($sub3eventx1->id));
        $this->assertEquals($this->generate_uuid($sub2eventz1), $this->fetch_event_uuid($sub3eventz1->id));
        $this->assertEquals($this->generate_uuid($sub3eventy1), $this->fetch_event_uuid($sub1eventy1->id));
        $this->assertEquals($this->generate_uuid($sub3eventx1), $this->fetch_event_uuid($sub1eventx1->id));
        $this->assertEquals($this->generate_uuid($sub1eventy1), $this->fetch_event_uuid($sub2eventy1->id));
    }

    /**
     * Generates a UUIDs for an "exported" event using the old logic.
     * 
     * @param object $event Event instance (must have an `id` property)
     * @return string Pseudo-UUID for the event in the form `<eventid>@<hostname>
     */
    protected function generate_uuid(object $event): string {
        return "$event->id@$this->wwwhostname";
    }

    /**
     * Generates an event simulating "self-import" deriving the UUID from the parent using the old logic via {@see generate_uuid}.
     *
     * @param object $parentevent Event to clone or "self-import"
     * @param array $data Associative array of field => value pairs for the new event
     * @return object Child event with the UUID derived from the parent's ID
     * @throws coding_exception
     */
    protected function event_clone(object $parentevent, array $data): object {
        return $this->getDataGenerator()->create_event($data + ['uuid' => $this->generate_uuid($parentevent)]);
    }

    /**
     * Gets the UUID of an event from the database.
     *
     * @param int $eventid Primary key of the event
     * @return string UUID of the event
     * @throws dml_exception
     */
    protected function fetch_event_uuid(int $eventid): string {
        global $DB;
        return $DB->get_record('event', ['id' => $eventid], 'uuid', MUST_EXIST)->uuid;
    }

    /**
     * @covers ::events_look_the_same
     */
    public function test_events_look_the_same(): void {
        $properties = [
            'name' => 'foo',
            'description' => '...',
            'format' => 0,
            'timestart' => 123,
            'timeduration' => 456,
            'priority' => 1,
            'location' => 'spam',
        ];
        // Events should look the same, if they are equal in all of these properties.
        $obj1 = (object) $properties;
        $obj2 = (object) $properties;
        $this->assertTrue(upgrade_calendar_event_uuids_task::events_look_the_same($obj1, $obj2));

        $obj2->name = 'bar';
        $this->assertFalse(upgrade_calendar_event_uuids_task::events_look_the_same($obj1, $obj2));

        $obj2->name = $obj1->name;
        $obj2->description = '';
        $this->assertFalse(upgrade_calendar_event_uuids_task::events_look_the_same($obj1, $obj2));

        $obj2->description = $obj1->description;
        $obj2->format = 1;
        $this->assertFalse(upgrade_calendar_event_uuids_task::events_look_the_same($obj1, $obj2));

        $obj2->format = $obj1->format;
        $obj2->timestart = 420;
        $this->assertFalse(upgrade_calendar_event_uuids_task::events_look_the_same($obj1, $obj2));

        $obj2->timestart = $obj1->timestart;
        $obj2->timeduration = 789;
        $this->assertFalse(upgrade_calendar_event_uuids_task::events_look_the_same($obj1, $obj2));

        $obj2->timeduration = $obj1->timeduration;
        $obj2->priority = 2;
        $this->assertFalse(upgrade_calendar_event_uuids_task::events_look_the_same($obj1, $obj2));

        $obj2->priority = $obj1->priority;
        $obj2->location = 'eggs';
        $this->assertFalse(upgrade_calendar_event_uuids_task::events_look_the_same($obj1, $obj2));

        // Differences in other properties should not be considered.
        $obj2->location = $obj1->location;
        $obj1->uuid = 'abc';
        $obj2->uuid = 'def';
        $this->assertTrue(upgrade_calendar_event_uuids_task::events_look_the_same($obj1, $obj2));
    }

    /**
     * @covers ::events_are_from_the_same_calendar
     */
    public function test_events_are_from_the_same_calendar(): void {
        $properties = [
            'categoryid' => 1,
            'courseid' => 2,
            'groupid' => 3,
            'userid' => 4,
        ];
        // Events should be considered from the same calendar, if they are equal in all of these properties,
        // even if the `subscriptionid` is different.
        $obj1 = (object) $properties;
        $obj2 = (object) $properties;
        $obj1->subscriptionid = 100;
        $obj2->subscriptionid = 200;
        $this->assertTrue(upgrade_calendar_event_uuids_task::events_are_from_the_same_calendar($obj1, $obj2));

        $obj2->categoryid = 0;
        $this->assertFalse(upgrade_calendar_event_uuids_task::events_are_from_the_same_calendar($obj1, $obj2));

        $obj2->categoryid = $obj1->categoryid;
        $obj2->courseid = 0;
        $this->assertFalse(upgrade_calendar_event_uuids_task::events_are_from_the_same_calendar($obj1, $obj2));

        $obj2->courseid = $obj1->courseid;
        $obj2->groupid = 0;
        $this->assertFalse(upgrade_calendar_event_uuids_task::events_are_from_the_same_calendar($obj1, $obj2));

        $obj2->groupid = $obj1->groupid;
        $obj2->userid = 0;
        $this->assertFalse(upgrade_calendar_event_uuids_task::events_are_from_the_same_calendar($obj1, $obj2));

        // If the `subscriptionid` is the same, the other properties should not be considered.
        $obj2->subscriptionid = $obj1->subscriptionid;
        $this->assertTrue(upgrade_calendar_event_uuids_task::events_are_from_the_same_calendar($obj1, $obj2));
    }

    /**
     * Tests that UUIDs are assigned to all calendar events with empty ones.
     *
     * @covers ::ensure_uuid_for_all_events
     * @throws coding_exception
     * @throws dml_exception
     */
    public function test_ensure_uuid_for_all_events(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();

        $hostname = "foo.bar";
        $task = new upgrade_calendar_event_uuids_task();
        $task->set_wwwhostname($hostname);

        $user1 = $generator->create_user();
        $user2 = $generator->create_user();
        $e1 = $generator->create_event(['userid' => $user1->id, 'uuidgenerator' => fn() => '']);
        $e2 = $generator->create_event(['userid' => $user1->id, 'uuid' => 'abc']);
        $e3 = $generator->create_event(['userid' => $user2->id, 'uuidgenerator' => fn() => '']);
        $this->assertEquals("", $e1->uuid);
        $this->assertEquals("abc", $e2->uuid);
        $this->assertEquals("", $e3->uuid);

        $num = $task->ensure_uuid_for_all_events();
        $this->assertEquals(2, $num);

        $e1 = $DB->get_record('event', ['id' => $e1->id]);
        $e2 = $DB->get_record('event', ['id' => $e2->id]);
        $e3 = $DB->get_record('event', ['id' => $e3->id]);

        $this->assertEquals("$e1->id@$hostname", $e1->uuid);
        $this->assertEquals("abc", $e2->uuid);
        $this->assertEquals("$e3->id@$hostname", $e3->uuid);
    }
}
