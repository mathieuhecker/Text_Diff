<?php
/**
 * Text_Diff
 *
 * General API for generating and formatting diffs - the differences
 * between two sequences of strings.
 *
 * $Horde: framework/Text_Diff/Diff.php,v 1.1 2004/01/09 20:14:29 chuck Exp $
 */
class Text_Diff {

    var $_edits;

    /**
     * Constructor.
     * Computes diff between sequences of strings.
     *
     * @param $from_lines array An array of strings.
     *        (Typically these are lines from a file.)
     * @param $to_lines array An array of strings.
     */
    function Text_Diff($from_lines, $to_lines)
    {
        $eng = &new Text_Diff_Engine();
        $this->_edits = $eng->diff($from_lines, $to_lines);
    }

    /**
     * Compute reversed diff.
     *
     * SYNOPSIS:
     *
     * $diff = &new Text_Diff($lines1, $lines2);
     * $rev = $diff->reverse();
     *
     * @return object  A Diff object representing the inverse of the
     *                 original diff. Note that we purposely don't return
     *                 a reference here, since this essentially is a
     *                 clone() method.
     */
    function reverse()
    {
        $rev = $this;
        $rev->_edits = array();
        foreach ($this->_edits as $edit) {
            $rev->_edits[] = $edit->reverse();
        }
        return $rev;
    }

    /**
     * Check for empty diff.
     *
     * @return boolean  True iff two sequences were identical.
     */
    function isEmpty()
    {
        foreach ($this->_edits as $edit) {
            if (!is_a($edit, 'Text_Diff_Op_copy')) {
                return false;
            }
        }
        return true;
    }

    /**
     * Compute the length of the Longest Common Subsequence (LCS).
     *
     * This is mostly for diagnostic purposed.
     *
     * @return int The length of the LCS.
     */
    function lcs()
    {
        $lcs = 0;
        foreach ($this->_edits as $edit) {
            if (is_a($edit, 'Text_Diff_Op_copy')) {
                $lcs += count($edit->orig);
            }
        }
        return $lcs;
    }

    /**
     * Get the original set of lines.
     *
     * This reconstructs the $from_lines parameter passed to the
     * constructor.
     *
     * @return array The original sequence of strings.
     */
    function orig()
    {
        $lines = array();

        foreach ($this->_edits as $edit) {
            if ($edit->orig) {
                array_splice($lines, count($lines), 0, $edit->orig);
            }
        }
        return $lines;
    }

    /**
     * Get the final set of lines.
     *
     * This reconstructs the $to_lines parameter passed to the
     * constructor.
     *
     * @return array The sequence of strings.
     */
    function final()
    {
        $lines = array();

        foreach ($this->_edits as $edit) {
            if ($edit->final) {
                array_splice($lines, count($lines), 0, $edit->final);
            }
        }
        return $lines;
    }

    /**
     * Check a diff for validity.
     *
     * This is here only for debugging purposes.
     */
    function _check($from_lines, $to_lines)
    {
        if (serialize($from_lines) != serialize($this->orig())) {
            trigger_error("Reconstructed original doesn't match", E_USER_ERROR);
        }
        if (serialize($to_lines) != serialize($this->final())) {
            trigger_error("Reconstructed final doesn't match", E_USER_ERROR);
        }

        $rev = $this->reverse();
        if (serialize($to_lines) != serialize($rev->orig())) {
            trigger_error("Reversed original doesn't match", E_USER_ERROR);
        }
        if (serialize($from_lines) != serialize($rev->final())) {
            trigger_error("Reversed final doesn't match", E_USER_ERROR);
        }

        $prevtype = null;
        foreach ($this->_edits as $edit) {
            if ($prevtype == get_class($edit)) {
                trigger_error("Edit sequence is non-optimal", E_USER_ERROR);
            }
            $prevtype = get_class($edit);
        }

        return true;
    }

}

/**
 * Class used internally by Diff to actually compute the diffs.
 *
 * The algorithm used here is mostly lifted from the perl module
 * Algorithm::Diff (version 1.06) by Ned Konz, which is available at:
 * http://www.perl.com/CPAN/authors/id/N/NE/NEDKONZ/Algorithm-Diff-1.06.zip
 *
 * More ideas are taken from:
 * http://www.ics.uci.edu/~eppstein/161/960229.html
 *
 * Some ideas (and a bit of code) are from from analyze.c, from GNU
 * diffutils-2.7, which can be found at:
 * ftp://gnudist.gnu.org/pub/gnu/diffutils/diffutils-2.7.tar.gz
 *
 * Some ideas (subdivision by NCHUNKS > 2, and some optimizations) are
 * from Geoffrey T. Dairiki <dairiki@dairiki.org>.
 *
 * @author Geoffrey T. Dairiki <dairiki@dairiki.org>
 * @access private
 */
class Text_Diff_Engine {

    function diff($from_lines, $to_lines)
    {
        $n_from = count($from_lines);
        $n_to = count($to_lines);

        $this->xchanged = $this->ychanged = array();
        $this->xv = $this->yv = array();
        $this->xind = $this->yind = array();
        unset($this->seq);
        unset($this->in_seq);
        unset($this->lcs);

        // Skip leading common lines.
        for ($skip = 0; $skip < $n_from && $skip < $n_to; $skip++) {
            if ($from_lines[$skip] != $to_lines[$skip]) {
                break;
            }
            $this->xchanged[$skip] = $this->ychanged[$skip] = false;
        }

        // Skip trailing common lines.
        $xi = $n_from; $yi = $n_to;
        for ($endskip = 0; --$xi > $skip && --$yi > $skip; $endskip++) {
            if ($from_lines[$xi] != $to_lines[$yi]) {
                break;
            }
            $this->xchanged[$xi] = $this->ychanged[$yi] = false;
        }

        // Ignore lines which do not exist in both files.
        for ($xi = $skip; $xi < $n_from - $endskip; $xi++) {
            $xhash[$from_lines[$xi]] = 1;
        }
        for ($yi = $skip; $yi < $n_to - $endskip; $yi++) {
            $line = $to_lines[$yi];
            if (($this->ychanged[$yi] = empty($xhash[$line]))) {
                continue;
            }
            $yhash[$line] = 1;
            $this->yv[] = $line;
            $this->yind[] = $yi;
        }
        for ($xi = $skip; $xi < $n_from - $endskip; $xi++) {
            $line = $from_lines[$xi];
            if (($this->xchanged[$xi] = empty($yhash[$line]))) {
                continue;
            }
            $this->xv[] = $line;
            $this->xind[] = $xi;
        }

        // Find the LCS.
        $this->_compareseq(0, count($this->xv), 0, count($this->yv));

        // Merge edits when possible.
        $this->_shiftBoundaries($from_lines, $this->xchanged, $this->ychanged);
        $this->_shiftBoundaries($to_lines, $this->ychanged, $this->xchanged);

        // Compute the edit operations.
        $edits = array();
        $xi = $yi = 0;
        while ($xi < $n_from || $yi < $n_to) {
            assert($yi < $n_to || $this->xchanged[$xi]);
            assert($xi < $n_from || $this->ychanged[$yi]);

            // Skip matching "snake".
            $copy = array();
            while ($xi < $n_from && $yi < $n_to
                   && !$this->xchanged[$xi] && !$this->ychanged[$yi]) {
                $copy[] = $from_lines[$xi++];
                ++$yi;
            }
            if ($copy)
                $edits[] = &new Text_Diff_Op_copy($copy);

            // Find deletes & adds.
            $delete = array();
            while ($xi < $n_from && $this->xchanged[$xi]) {
                $delete[] = $from_lines[$xi++];
            }

            $add = array();
            while ($yi < $n_to && $this->ychanged[$yi]) {
                $add[] = $to_lines[$yi++];
            }

            if ($delete && $add) {
                $edits[] = &new Text_Diff_Op_change($delete, $add);
            } elseif ($delete) {
                $edits[] = &new Text_Diff_Op_delete($delete);
            } elseif ($add) {
                $edits[] = &new Text_Diff_Op_add($add);
            }
        }
        return $edits;
    }

    /**
     * Divide the Largest Common Subsequence (LCS) of the sequences
     * [XOFF, XLIM) and [YOFF, YLIM) into NCHUNKS approximately
     * equally sized segments.
     *
     * Returns (LCS, PTS).  LCS is the length of the LCS. PTS is an
     * array of NCHUNKS+1 (X, Y) indexes giving the diving points
     * between sub sequences.  The first sub-sequence is contained in
     * [X0, X1), [Y0, Y1), the second in [X1, X2), [Y1, Y2) and so on.
     * Note that (X0, Y0) == (XOFF, YOFF) and (X[NCHUNKS], Y[NCHUNKS])
     * == (XLIM, YLIM).
     *
     * This function assumes that the first lines of the specified
     * portions of the two files do not match, and likewise that the
     * last lines do not match.  The caller must trim matching lines
     * from the beginning and end of the portions it is going to
     * specify.
     */
    function _diag ($xoff, $xlim, $yoff, $ylim, $nchunks)
    {
        $flip = false;

        if ($xlim - $xoff > $ylim - $yoff) {
            // Things seems faster (I'm not sure I understand why)
            // when the shortest sequence in X.
            $flip = true;
            list ($xoff, $xlim, $yoff, $ylim)
                = array($yoff, $ylim, $xoff, $xlim);
        }

        if ($flip) {
            for ($i = $ylim - 1; $i >= $yoff; $i--) {
                $ymatches[$this->xv[$i]][] = $i;
            }
        } else {
            for ($i = $ylim - 1; $i >= $yoff; $i--) {
                $ymatches[$this->yv[$i]][] = $i;
            }
        }

        $this->lcs = 0;
        $this->seq[0]= $yoff - 1;
        $this->in_seq = array();
        $ymids[0] = array();

        $numer = $xlim - $xoff + $nchunks - 1;
        $x = $xoff;
        for ($chunk = 0; $chunk < $nchunks; $chunk++) {
            if ($chunk > 0) {
                for ($i = 0; $i <= $this->lcs; $i++) {
                    $ymids[$i][$chunk-1] = $this->seq[$i];
                }
            }

            $x1 = $xoff + (int)(($numer + ($xlim-$xoff)*$chunk) / $nchunks);
            for (; $x < $x1; $x++) {
                $line = $flip ? $this->yv[$x] : $this->xv[$x];
                if (empty($ymatches[$line])) {
                    continue;
                }
                $matches = $ymatches[$line];
                foreach ($matches as $y) {
                    if (empty($this->in_seq[$y])) {
                        $k = $this->_lcsPos($y);
                        assert($k > 0);
                        $ymids[$k] = $ymids[$k-1];
                        break;
                    }
                }

                while (list($junk, $y) = each($matches)) {
                    if ($y > $this->seq[$k - 1]) {
                        assert($y < $this->seq[$k]);
                        // Optimization: this is a common case: next
                        // match is just replacing previous match.
                        $this->in_seq[$this->seq[$k]] = false;
                        $this->seq[$k] = $y;
                        $this->in_seq[$y] = 1;
                    } elseif (empty($this->in_seq[$y])) {
                        $k = $this->_lcsPos($y);
                        assert($k > 0);
                        $ymids[$k] = $ymids[$k-1];
                    }
                }
            }
        }

        $seps[] = $flip ? array($yoff, $xoff) : array($xoff, $yoff);
        $ymid = $ymids[$this->lcs];
        for ($n = 0; $n < $nchunks - 1; $n++) {
            $x1 = $xoff + (int)(($numer + ($xlim - $xoff) * $n) / $nchunks);
            $y1 = $ymid[$n] + 1;
            $seps[] = $flip ? array($y1, $x1) : array($x1, $y1);
        }
        $seps[] = $flip ? array($ylim, $xlim) : array($xlim, $ylim);

        return array($this->lcs, $seps);
    }

    function _lcsPos($ypos)
    {
        $end = $this->lcs;
        if ($end == 0 || $ypos > $this->seq[$end]) {
            $this->seq[++$this->lcs] = $ypos;
            $this->in_seq[$ypos] = 1;
            return $this->lcs;
        }

        $beg = 1;
        while ($beg < $end) {
            $mid = (int)(($beg + $end) / 2);
            if ($ypos > $this->seq[$mid]) {
                $beg = $mid + 1;
            } else {
                $end = $mid;
            }
        }

        assert($ypos != $this->seq[$end]);

        $this->in_seq[$this->seq[$end]] = false;
        $this->seq[$end] = $ypos;
        $this->in_seq[$ypos] = 1;
        return $end;
    }

    /**
     * Find LCS of two sequences.
     *
     * The results are recorded in the vectors $this->{x,y}changed[],
     * by storing a 1 in the element for each line that is an
     * insertion or deletion (ie. is not in the LCS).
     *
     * The subsequence of file 0 is [XOFF, XLIM) and likewise for file
     * 1.
     *
     * Note that XLIM, YLIM are exclusive bounds.  All line numbers
     * are origin-0 and discarded lines are not counted.
     */
    function _compareseq ($xoff, $xlim, $yoff, $ylim)
    {
        // Slide down the bottom initial diagonal.
        while ($xoff < $xlim && $yoff < $ylim
               && $this->xv[$xoff] == $this->yv[$yoff]) {
            ++$xoff;
            ++$yoff;
        }

        // Slide up the top initial diagonal.
        while ($xlim > $xoff && $ylim > $yoff
               && $this->xv[$xlim - 1] == $this->yv[$ylim - 1]) {
            --$xlim;
            --$ylim;
        }

        if ($xoff == $xlim || $yoff == $ylim) {
            $lcs = 0;
        } else {
            // This is ad hoc but seems to work well.  $nchunks =
            // sqrt(min($xlim - $xoff, $ylim - $yoff) / 2.5); $nchunks
            // = max(2,min(8,(int)$nchunks));
            $nchunks = min(7, $xlim - $xoff, $ylim - $yoff) + 1;
            list($lcs, $seps)
                = $this->_diag($xoff, $xlim, $yoff, $ylim, $nchunks);
        }

        if ($lcs == 0) {
            // X and Y sequences have no common subsequence: mark all
            // changed.
            while ($yoff < $ylim) {
                $this->ychanged[$this->yind[$yoff++]] = 1;
            }
            while ($xoff < $xlim) {
                $this->xchanged[$this->xind[$xoff++]] = 1;
            }
        } else {
            // Use the partitions to split this problem into
            // subproblems.
            reset($seps);
            $pt1 = $seps[0];
            while ($pt2 = next($seps)) {
                $this->_compareseq ($pt1[0], $pt2[0], $pt1[1], $pt2[1]);
                $pt1 = $pt2;
            }
        }
    }

    /**
     * Adjust inserts/deletes of identical lines to join changes as
     * much as possible.
     *
     * We do something when a run of changed lines include a line at
     * one end and has an excluded, identical line at the other.  We
     * are free to choose which identical line is included.
     * `compareseq' usually chooses the one at the beginning, but
     * usually it is cleaner to consider the following identical line
     * to be the "change".
     *
     * This is extracted verbatim from analyze.c (GNU diffutils-2.7).
     */
    function _shiftBoundaries($lines, &$changed, $other_changed)
    {
        $i = 0;
        $j = 0;

        assert('count($lines) == count($changed)');
        $len = count($lines);
        $other_len = count($other_changed);

        while (1) {
            /* Scan forward to find the beginning of another run of
             * changes. Also keep track of the corresponding point in
             * the other file.
             *
             * Throughout this code, $i and $j are adjusted together
             * so that the first $i elements of $changed and the first
             * $j elements of $other_changed both contain the same
             * number of zeros (unchanged lines).
             *
             * Furthermore, $j is always kept so that $j == $other_len
             * or $other_changed[$j] == false. */
            while ($j < $other_len && $other_changed[$j]) {
                $j++;
            }

            while ($i < $len && ! $changed[$i]) {
                assert('$j < $other_len && ! $other_changed[$j]');
                $i++; $j++;
                while ($j < $other_len && $other_changed[$j]) {
                    $j++;
                }
            }

            if ($i == $len) {
                break;
            }

            $start = $i;

            // Find the end of this run of changes.
            while (++$i < $len && $changed[$i]) {
                continue;
            }

            do {
                /* Record the length of this run of changes, so that
                 * we can later determine whether the run has
                 * grown. */
                $runlength = $i - $start;

                /* Move the changed region back, so long as the
                 * previous unchanged line matches the last changed
                 * one.  This merges with previous changed regions. */
                while ($start > 0 && $lines[$start - 1] == $lines[$i - 1]) {
                    $changed[--$start] = 1;
                    $changed[--$i] = false;
                    while ($start > 0 && $changed[$start - 1]) {
                        $start--;
                    }
                    assert('$j > 0');
                    while ($other_changed[--$j]) {
                        continue;
                    }
                    assert('$j >= 0 && !$other_changed[$j]');
                }

                /* Set CORRESPONDING to the end of the changed run, at
                 * the last point where it corresponds to a changed
                 * run in the other file. CORRESPONDING == LEN means
                 * no such point has been found. */
                $corresponding = $j < $other_len ? $i : $len;

                /* Move the changed region forward, so long as the
                 * first changed line matches the following unchanged
                 * one.  This merges with following changed regions.
                 * Do this second, so that if there are no merges, the
                 * changed region is moved forward as far as
                 * possible. */
                while ($i < $len && $lines[$start] == $lines[$i]) {
                    $changed[$start++] = false;
                    $changed[$i++] = 1;
                    while ($i < $len && $changed[$i]) {
                        $i++;
                    }

                    assert('$j < $other_len && ! $other_changed[$j]');
                    $j++;
                    if ($j < $other_len && $other_changed[$j]) {
                        $corresponding = $i;
                        while ($j < $other_len && $other_changed[$j]) {
                            $j++;
                        }
                    }
                }
            } while ($runlength != $i - $start);

            /* If possible, move the fully-merged run of changes back
             * to a corresponding run in the other file. */
            while ($corresponding < $i) {
                $changed[--$start] = 1;
                $changed[--$i] = 0;
                assert('$j > 0');
                while ($other_changed[--$j]) {
                    continue;
                }
                assert('$j >= 0 && !$other_changed[$j]');
            }
        }
    }

}

class Text_Diff_Op {

    var $orig;
    var $final;

    function reverse()
    {
        trigger_error('Abstract method', E_USER_ERROR);
    }

    function norig()
    {
        return $this->orig ? count($this->orig) : 0;
    }

    function nfinal()
    {
        return $this->final ? count($this->final) : 0;
    }

}

class Text_Diff_Op_copy extends Text_Diff_Op {

    function Text_Diff_Op_copy($orig, $final = false)
    {
        if (!is_array($final)) {
            $final = $orig;
        }
        $this->orig = $orig;
        $this->final = $final;
    }

    function &reverse()
    {
        return $diff = new Text_Diff_Op_copy($this->final, $this->orig);
    }

}

class Text_Diff_Op_delete extends Text_Diff_Op {

    function Text_Diff_Op_delete($lines)
    {
        $this->orig = $lines;
        $this->final = false;
    }

    function &reverse()
    {
        return $diff = new Text_Diff_Op_add($this->orig);
    }

}

class Text_Diff_Op_add extends Text_Diff_Op {

    function Text_Diff_Op_add($lines)
    {
        $this->final = $lines;
        $this->orig = false;
    }

    function &reverse()
    {
        return $diff = new Text_Diff_Op_delete($this->final);
    }

}

class Text_Diff_Op_change extends Text_Diff_Op {

    function Text_Diff_Op_change($orig, $final)
    {
        $this->orig = $orig;
        $this->final = $final;
    }

    function &reverse()
    {
        return $diff = new _DiffOp_Change($this->final, $this->orig);
    }

}
