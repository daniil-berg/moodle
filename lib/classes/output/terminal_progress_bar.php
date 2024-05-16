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
 * Definition of the {@see \core\output\terminal_progress_bar} class.
 *
 * @package    core_output
 * @copyright  2025 Daniil Fajnberg <d.fajnberg@tu-berlin.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace core\output;

use core\exception\coding_exception;
use core_calendar\local\event\forms\update;

/**
 * Represents a terminal progress bar for tracking and visualizing progress in console-like scripts.
 *
 * This class provides functionality to construct and output a textual progress bar in the terminal. Its width and the frequency of
 * rendered progress updates can be customized. The progress bar updates dynamically and can handle non-TTY environments gracefully.
 *
 * @copyright  2025 Daniil Fajnberg <d.fajnberg@tu-berlin.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class terminal_progress_bar {
    /**
     * @var int Represents the total number of steps (corresponding to 100% progress).
     */
    protected int $stepstotal;

    /**
     * @var int Controls when the {@see update} method actually prints the progress bar.
     */
    protected int $stepsbetweenoutputs;

    /**
     * @var int The width of the progress bar (in number of characters).
     */
    protected int $width;

    /**
     * @var bool Whether the number of steps done should be displayed.
     */
    public bool $showsteps;

    /**
     * @var bool Whether the progress percentage should be displayed.
     */
    public bool $showpercentage;

    /**
     * Constructor for the class.
     *
     * @param int $stepstotal Total number of steps (corresponding to 100% progress). Must be greater than or equal to 0.
     * @param int $stepsbetweenoutputs Number of steps between outputs. The {@see update} method will print, if passed a multiple of
     *                                 this number. Must be greater than or equal to 1. Defaults to 1.
     * @param int $width Width of the progress bar in characters. Must be greater than or equal to 1. Defaults to 100.
     * @param bool $showsteps Whether the number of steps done should be displayed. Defaults to `true`.
     * @param bool $showpercentage Whether the progress percentage should be displayed. Defaults to `true`.
     * @param int|null $updatestepsnow If passed an integer, the {@see update} method is called immediately after initialization
     *                                 with that argument.
     * @throws coding_exception If any of the provided parameters have invalid values.
     */
    public function __construct(
        int $stepstotal,
        int $stepsbetweenoutputs = 1,
        int $width = 100,
        bool $showsteps = true,
        bool $showpercentage = true,
        int|null $updatestepsnow = null,
    ) {
        if ($stepstotal < 0) {
            throw new coding_exception('steps_total must be greater than or equal to 0');
        }
        if ($stepsbetweenoutputs < 1) {
            throw new coding_exception('steps_between_outputs must be greater than or equal to 1');
        }
        if ($width < 1) {
            throw new coding_exception('width must be greater than or equal to 1');
        }
        $this->stepstotal = $stepstotal;
        $this->stepsbetweenoutputs = $stepsbetweenoutputs;
        $this->width = $width;
        $this->showsteps = $showsteps;
        $this->showpercentage = $showpercentage;
        if (!is_null($updatestepsnow)) {
            $this->update($updatestepsnow);
        }
    }

    /**
     * Returns the progress bar string.
     *
     * @param int $stepsdone Number of steps that have been done already
     * @return string Progress bar
     */
    public function get_string(int $stepsdone): string {
        $ratio = min(1, max(0, $stepsdone) / $this->stepstotal);
        $filled = intval($ratio * $this->width);
        $empty = $this->width - $filled;
        $output = sprintf("[%'={$filled}s>%-{$empty}s]", "", "");
        if ($this->showsteps) {
            $output .= " - $stepsdone/$this->stepstotal";
        }
        if ($this->showpercentage) {
            $output .= sprintf(' (%3d%%)', $ratio * 100);
        }
        return $output;
    }

    /**
     * Conditionally prints out the progress bar.
     *
     * @param int $stepsdone Number of steps that have been done already. Output is only printed, if this number is a multiple of
     *                       {@see stepsbetweenoutputs} or equal to {@see stepstotal}; otherwise this method does nothing.
     */
    public function update(int $stepsdone): void {
        if ($stepsdone !== $this->stepstotal && $stepsdone % $this->stepsbetweenoutputs !== 0) {
            return;
        }
        $progress = $this->get_string($stepsdone);
        $eol = "\n";
        if (stream_isatty(STDOUT)) {
            $progress = "\r$progress";
            if ($stepsdone < $this->stepstotal) {
                $eol = "";
            }
        }
        mtrace($progress, $eol);
    }
}
