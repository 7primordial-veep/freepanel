<?php
/**
 * CloudPanel PHP deobfuscator.
 *
 * Reverses two obfuscation techniques applied to CloudPanel source:
 *   1) String escape encoding: "\x74\141\x72" -> "tar"
 *      (handled automatically by PhpParser's PrettyPrinter, which re-emits
 *       string values using minimal escaping)
 *   2) Goto-flattening: statements scrambled and connected via label/goto
 *      chains (handled by the passes below)
 *
 * Usage:
 *   php tools/deobfuscate.php <file_or_directory> [<file_or_directory> ...]
 *
 * Files are rewritten in place.
 */

require __DIR__ . '/../source/vendor/autoload.php';

use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\Node\Expr;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter;

/**
 * Pass 1: linearize goto-connected statement lists so labels appear in
 * execution order. Gotos remain in the output - later passes fold them away.
 */
class GotoLinearizer extends NodeVisitorAbstract
{
    public function leaveNode(Node $node)
    {
        if (!isset($node->stmts) || !is_array($node->stmts)) return null;
        $node->stmts = $this->linearize($node->stmts);
        return null;
    }

    private function linearize(array $stmts): array
    {
        $hasLabel = false;
        foreach ($stmts as $s) {
            if ($s instanceof Stmt\Label) { $hasLabel = true; break; }
        }
        if (!$hasLabel) return $stmts;

        [$blocks, $order] = $this->splitBlocks($stmts);

        // If the $entry$ block is a single goto, start at its target.
        $entry = '$entry$';
        if (isset($blocks[$entry]) && count($blocks[$entry]) === 1
            && $blocks[$entry][0] instanceof Stmt\Goto_) {
            $entry = $blocks[$entry][0]->name->toString();
            unset($blocks['$entry$']);
            $order = array_values(array_filter($order, fn($l) => $l !== '$entry$'));
        }

        $output = [];
        $visited = [];
        $this->walkLabel($entry, $blocks, $order, $visited, $output);
        return $this->stripRedundantGotos($output);
    }

    /**
     * DFS walk from a label, emitting block bodies in execution order.
     * Primary successor (fall-through or terminating goto) is walked first,
     * then any branch targets referenced by inner ifs.
     */
    private function walkLabel(string $label, array $blocks, array $order, array &$visited, array &$output): void
    {
        if (isset($visited[$label])) return;
        if (!isset($blocks[$label])) return;
        $visited[$label] = true;

        if ($label !== '$entry$') {
            $output[] = new Stmt\Label(new Node\Identifier($label));
        }

        $block = $blocks[$label];
        foreach ($block as $s) $output[] = $s;

        // Primary successor.
        $last = end($block);
        if ($last instanceof Stmt\Goto_) {
            $this->walkLabel($last->name->toString(), $blocks, $order, $visited, $output);
        } elseif ($last instanceof Stmt\Return_ || $last instanceof Stmt\Throw_) {
            // Dead end.
        } else {
            $next = $this->nextLabel($label, $order);
            if ($next !== null) {
                $output[] = new Stmt\Goto_(new Node\Identifier($next));
                $this->walkLabel($next, $blocks, $order, $visited, $output);
            }
        }

        // Secondary: walk any branch targets from inner statements.
        foreach ($block as $s) {
            $inner = [];
            $this->collectGotoTargets($s, $inner);
            foreach ($inner as $target) {
                $this->walkLabel($target, $blocks, $order, $visited, $output);
            }
        }
    }

    private function splitBlocks(array $stmts): array
    {
        $blocks = [];
        $order = [];
        $current = '$entry$';
        $buf = [];
        foreach ($stmts as $s) {
            if ($s instanceof Stmt\Label) {
                $blocks[$current] = $buf;
                $order[] = $current;
                $current = $s->name->toString();
                $buf = [];
            } else {
                $buf[] = $s;
            }
        }
        $blocks[$current] = $buf;
        $order[] = $current;
        return [$blocks, $order];
    }

    private function nextLabel(string $label, array $order): ?string
    {
        $i = array_search($label, $order, true);
        if ($i === false || $i + 1 >= count($order)) return null;
        return $order[$i + 1];
    }

    private function collectGotoTargets(Node $node, array &$queue): void
    {
        $t = new NodeTraverser();
        $t->addVisitor(new class($queue) extends NodeVisitorAbstract {
            private array $q;
            public function __construct(array &$q) { $this->q =& $q; }
            public function enterNode(Node $n) {
                if ($n instanceof Stmt\Goto_) $this->q[] = $n->name->toString();
            }
        });
        $t->traverse([$node]);
    }

    private function stripRedundantGotos(array $stmts): array
    {
        $out = [];
        $n = count($stmts);
        for ($i = 0; $i < $n; $i++) {
            $s = $stmts[$i];
            if ($s instanceof Stmt\Goto_ && $i + 1 < $n
                && $stmts[$i + 1] instanceof Stmt\Label
                && $stmts[$i + 1]->name->toString() === $s->name->toString()) {
                continue;
            }
            $out[] = $s;
        }
        return $out;
    }
}

/**
 * Pass 2: collapse label-to-label hops.
 *
 * If a label L is followed immediately by `goto L2` (and nothing else uses L
 * as a label's body), rewrite every `goto L` to `goto L2` and drop L's empty
 * body. Repeatedly applied, this collapses chains like L1: goto L2; L2: goto L3;
 */
function labelHopCollapse(array &$ast): bool
{
    $changed = false;
    $t = new NodeTraverser();
    $t->addVisitor(new class($changed) extends NodeVisitorAbstract {
        private bool $changed;
        public function __construct(bool &$c) { $this->changed =& $c; }
        public function leaveNode(Node $node) {
            if (!isset($node->stmts) || !is_array($node->stmts)) return null;
            $stmts = $node->stmts;
            $n = count($stmts);
            $redirects = [];
            for ($i = 0; $i < $n - 1; $i++) {
                $a = $stmts[$i];
                $b = $stmts[$i + 1];
                if ($a instanceof Stmt\Label && $b instanceof Stmt\Goto_) {
                    $src = $a->name->toString();
                    $dst = $b->name->toString();
                    if ($src === $dst) continue;
                    // Only safe if L's "body" is literally just that goto
                    // (i.e., next stmt is another label, or the goto is alone).
                    if ($i + 2 >= $n || $stmts[$i + 2] instanceof Stmt\Label) {
                        $redirects[$src] = $dst;
                    }
                }
            }
            if (!$redirects) return null;

            // Resolve chains.
            foreach ($redirects as $src => $dst) {
                while (isset($redirects[$dst])) $dst = $redirects[$dst];
                $redirects[$src] = $dst;
            }

            // Rewrite all goto statements in this block (incl. nested).
            $localChanged = false;
            $rewriter = new NodeTraverser();
            $rewriter->addVisitor(new class($redirects, $localChanged) extends NodeVisitorAbstract {
                private array $r;
                private bool $c;
                public function __construct(array $r, bool &$c) { $this->r = $r; $this->c =& $c; }
                public function enterNode(Node $n) {
                    if ($n instanceof Stmt\Goto_) {
                        $name = $n->name->toString();
                        if (isset($this->r[$name])) {
                            $n->name = new Node\Identifier($this->r[$name]);
                            $this->c = true;
                        }
                    }
                }
            });
            $rewriter->traverse($node->stmts);
            if ($localChanged) $this->changed = true;

            // Drop the now-orphan labels and their goto bodies.
            $toRemove = array_keys($redirects);
            $newStmts = [];
            $n2 = count($node->stmts);
            for ($i = 0; $i < $n2; $i++) {
                $s = $node->stmts[$i];
                if ($s instanceof Stmt\Label && in_array($s->name->toString(), $toRemove, true)) {
                    // Skip label + the single goto that follows.
                    if ($i + 1 < $n2 && $node->stmts[$i + 1] instanceof Stmt\Goto_) {
                        $i++; // consume the goto too
                    }
                    $this->changed = true;
                    continue;
                }
                $newStmts[] = $s;
            }
            $node->stmts = $newStmts;
            return null;
        }
    });
    $t->traverse($ast);
    return $changed;
}

/**
 * Pass 3: strip dead labels (labels never referenced by any goto within scope).
 *
 * Scope-wise this is conservative: a label inside a function is only reachable
 * from gotos in that same function (PHP forbids cross-function goto), so
 * counting references within the same ClassMethod / Function_ / Closure body
 * is sufficient.
 */
class DeadLabelRemover extends NodeVisitorAbstract
{
    public function leaveNode(Node $node) {
        if ($node instanceof Stmt\ClassMethod || $node instanceof Stmt\Function_
            || $node instanceof Expr\Closure || $node instanceof Stmt\Foreach_
            || $node instanceof Stmt\For_ || $node instanceof Stmt\While_
            || $node instanceof Stmt\Do_ || $node instanceof Stmt\If_
            || $node instanceof Stmt\Else_ || $node instanceof Stmt\ElseIf_
            || $node instanceof Stmt\TryCatch || $node instanceof Stmt\Catch_
            || $node instanceof Stmt\Finally_ || $node instanceof Stmt\Switch_
            || $node instanceof Stmt\Case_) {
            $this->process($node);
        }
        return null;
    }
    private function process(Node $root): void {
        // For function-like scopes, gotos can't leave so we only need labels
        // that are targeted within this subtree.
        if (!isset($root->stmts) || !is_array($root->stmts)) return;
        $targets = [];
        $t = new NodeTraverser();
        $t->addVisitor(new class($targets) extends NodeVisitorAbstract {
            private array $t;
            public function __construct(array &$t) { $this->t =& $t; }
            public function enterNode(Node $n) {
                if ($n instanceof Stmt\Goto_) $this->t[$n->name->toString()] = true;
            }
        });
        $t->traverse($root->stmts);

        // Walk every nested stmt list, remove labels not in $targets.
        $stripper = new NodeTraverser();
        $stripper->addVisitor(new class($targets) extends NodeVisitorAbstract {
            private array $t;
            public function __construct(array $t) { $this->t = $t; }
            public function leaveNode(Node $n) {
                if (isset($n->stmts) && is_array($n->stmts)) {
                    $n->stmts = array_values(array_filter($n->stmts, function ($s) {
                        if ($s instanceof Stmt\Label && !isset($this->t[$s->name->toString()])) {
                            return false;
                        }
                        return true;
                    }));
                }
                return null;
            }
        });
        $stripper->traverse($root->stmts);

        // Filter the root's own stmts array (top-level labels in the function).
        $root->stmts = array_values(array_filter($root->stmts, function ($s) use ($targets) {
            if ($s instanceof Stmt\Label && !isset($targets[$s->name->toString()])) {
                return false;
            }
            return true;
        }));
    }
}

/**
 * Pass 4: fold `if (C) { goto L; } ...stmts... L:` into `if (!C) { ...stmts... }`
 * when L has exactly one incoming goto (the one in the if) and the stmts
 * between the if and L don't branch out.
 *
 * Also handles the paired form:
 *   if (C) { goto A; } ...s1... goto B; A: ...s2... B:
 * when A and B each have exactly one incoming reference.
 */
function ifGotoFold(array &$ast): bool
{
    $changed = false;
    $t = new NodeTraverser();
    $t->addVisitor(new class($changed) extends NodeVisitorAbstract {
        private bool $changed;
        public function __construct(bool &$c) { $this->changed =& $c; }
        public function leaveNode(Node $node) {
            if (!isset($node->stmts) || !is_array($node->stmts)) return null;
            $node->stmts = $this->fold($node->stmts);
            return null;
        }
        private function fold(array $stmts): array {
            $refs = $this->gotoRefs($stmts);
            $n = count($stmts);
            for ($i = 0; $i < $n; $i++) {
                $s = $stmts[$i];
                if (!($s instanceof Stmt\If_)) continue;
                if ($s->elseifs || $s->else !== null) continue;
                if (!$s->stmts) continue;
                $bodyLast = end($s->stmts);
                if (!($bodyLast instanceof Stmt\Goto_)) continue;
                $skipLabel = $bodyLast->name->toString();
                $skipRefs = $refs[$skipLabel] ?? 0;
                if ($skipRefs < 1) continue;

                // If the if body has real stmts before the terminal `goto L`, this is
                // the if/else form: `if (C) { stmts1; goto L; } stmts2; L:`
                // → `if (C) { stmts1; } else { stmts2; }`
                $thenBodyLen = count($s->stmts) - 1;
                $hasThenBody = $thenBodyLen > 0;

                // Find skipLabel at this level.
                $labelIdx = null;
                for ($j = $i + 1; $j < $n; $j++) {
                    if ($stmts[$j] instanceof Stmt\Label
                        && $stmts[$j]->name->toString() === $skipLabel) {
                        $labelIdx = $j;
                        break;
                    }
                }
                // If the label isn't in this scope, it lives in an enclosing scope.
                // Treat the rest of the current scope as the "body" to guard with if (!C).
                // This rewrites: if (C) { goto L; } post-stmts  →  if (!C) { post-stmts }
                // The goto L semantics (leave this scope early) is preserved by the fact
                // that after we consume post-stmts, the current scope ends naturally.
                if ($labelIdx === null) {
                    $labelIdx = $n; // virtual label index = end of scope
                }

                // The "if body has stmts then goto L" form is itself an if/else:
                //   if (C) { s1; goto L; } s2; L:  →  if (C) { s1; } else { s2; }
                if ($hasThenBody && $labelIdx < $n) {
                    $thenStmts = array_slice($s->stmts, 0, $thenBodyLen);
                    $elseStmts = array_slice($stmts, $i + 1, $labelIdx - $i - 1);
                    $newIf = new Stmt\If_($s->cond, [
                        'stmts' => $thenStmts,
                        'elseifs' => [],
                        'else' => $elseStmts ? new Stmt\Else_($elseStmts) : null,
                    ]);
                    if ($skipRefs === 1) {
                        array_splice($stmts, $i, $labelIdx - $i + 1, [$newIf]);
                    } else {
                        array_splice($stmts, $i, $labelIdx - $i, [$newIf]);
                    }
                    $this->changed = true;
                    return $this->fold($stmts);
                }

                // Try paired if/else form:
                //   if (C) { goto A; } s1... goto B; A: s2... B:
                // Both A and B must have a single reference for the full
                // two-sided fold; otherwise drop back to single-sided fold.
                $elseCandidateStart = $i + 1;
                $elseCandidateEnd = $labelIdx - 1;
                $hasElse = false;
                $endIdx = null;
                if ($skipRefs === 1
                    && $elseCandidateEnd >= $elseCandidateStart
                    && $stmts[$elseCandidateEnd] instanceof Stmt\Goto_) {
                    $endLabel = $stmts[$elseCandidateEnd]->name->toString();
                    if (($refs[$endLabel] ?? 0) === 1) {
                        for ($k = $labelIdx + 1; $k < $n; $k++) {
                            if ($stmts[$k] instanceof Stmt\Label
                                && $stmts[$k]->name->toString() === $endLabel) {
                                $endIdx = $k;
                                break;
                            }
                        }
                    }
                    if ($endIdx !== null) $hasElse = true;
                }

                if ($hasElse) {
                    $elseStmts = array_slice($stmts, $elseCandidateStart, $elseCandidateEnd - $elseCandidateStart);
                    $thenStmts = array_slice($stmts, $labelIdx + 1, $endIdx - $labelIdx - 1);

                    // Drop trailing `goto $endLabel` from then-body (end-of-branch merge).
                    $lastThen = end($thenStmts);
                    if ($lastThen instanceof Stmt\Goto_ && $lastThen->name->toString() === $endLabel) {
                        array_pop($thenStmts);
                    }

                    $newIf = new Stmt\If_($s->cond, [
                        'stmts' => $thenStmts,
                        'elseifs' => [],
                        'else' => new Stmt\Else_($elseStmts),
                    ]);
                    array_splice($stmts, $i, $endIdx - $i + 1, [$newIf]);
                    $this->changed = true;
                    return $this->fold($stmts);
                } else {
                    // Single-sided: fold stmts between if and L into `if (!C) { ... }`.
                    // If skipLabel has multiple incoming refs, keep the label in place.
                    $bodyStmts = array_slice($stmts, $elseCandidateStart, $labelIdx - $elseCandidateStart);
                    if (!$bodyStmts) continue;

                    // If body ends in `goto $skipLabel` (redundant fall-through to merge),
                    // drop that terminal goto.
                    $last = end($bodyStmts);
                    $hadMergeGoto = false;
                    if ($last instanceof Stmt\Goto_ && $last->name->toString() === $skipLabel) {
                        array_pop($bodyStmts);
                        $hadMergeGoto = true;
                    }

                    $negCond = new Expr\BooleanNot($s->cond);
                    if ($s->cond instanceof Expr\BooleanNot) {
                        $negCond = $s->cond->expr;
                    }
                    $newIf = new Stmt\If_($negCond, [
                        'stmts' => $bodyStmts,
                        'elseifs' => [],
                        'else' => null,
                    ]);

                    // How many refs did we consume? The skip from the if itself,
                    // plus the trailing goto if we dropped it.
                    $consumedRefs = 1 + ($hadMergeGoto ? 1 : 0);
                    if ($skipRefs <= $consumedRefs) {
                        // Replace [if, ...body..., label L] with [newIf]
                        array_splice($stmts, $i, $labelIdx - $i + 1, [$newIf]);
                    } else {
                        // Replace [if, ...body...] with [newIf] — keep label L.
                        array_splice($stmts, $i, $labelIdx - $i, [$newIf]);
                    }
                    $this->changed = true;
                    return $this->fold($stmts);
                }
            }
            return $stmts;
        }

        private function hasStrayJump(array $stmts): bool {
            // Any goto inside these stmts whose target is NOT a label also
            // inside these stmts means the body escapes and we can't safely
            // fold.
            $localLabels = [];
            $walk = new NodeTraverser();
            $walk->addVisitor(new class($localLabels) extends NodeVisitorAbstract {
                private array $l;
                public function __construct(array &$l) { $this->l =& $l; }
                public function enterNode(Node $n) {
                    if ($n instanceof Stmt\Label) $this->l[$n->name->toString()] = true;
                }
            });
            $walk->traverse($stmts);

            $escape = false;
            $walk2 = new NodeTraverser();
            $walk2->addVisitor(new class($localLabels, $escape) extends NodeVisitorAbstract {
                private array $l; private bool $e;
                public function __construct(array $l, bool &$e) { $this->l = $l; $this->e =& $e; }
                public function enterNode(Node $n) {
                    if ($n instanceof Stmt\Goto_ && !isset($this->l[$n->name->toString()])) {
                        $this->e = true;
                    }
                }
            });
            $walk2->traverse($stmts);
            return $escape;
        }

        /** Count goto references per label in the given stmt list (recursive). */
        private function gotoRefs(array $stmts): array {
            $c = [];
            $t = new NodeTraverser();
            $t->addVisitor(new class($c) extends NodeVisitorAbstract {
                private array $c;
                public function __construct(array &$c) { $this->c =& $c; }
                public function enterNode(Node $n) {
                    if ($n instanceof Stmt\Goto_) {
                        $name = $n->name->toString();
                        $this->c[$name] = ($this->c[$name] ?? 0) + 1;
                    }
                }
            });
            $t->traverse($stmts);
            return $c;
        }
    });
    $t->traverse($ast);
    return $changed;
}

/**
 * Rewrite switch-case `goto MERGE_LABEL` where MERGE_LABEL is the label
 * immediately following the switch. This is the obfuscator's transform for
 * `break`, so we reverse it.
 */
function switchBreakPass(array &$ast): bool
{
    $changed = false;
    $t = new NodeTraverser();
    $t->addVisitor(new class($changed) extends NodeVisitorAbstract {
        private bool $changed;
        public function __construct(bool &$c) { $this->changed =& $c; }
        public function leaveNode(Node $node) {
            if (!isset($node->stmts) || !is_array($node->stmts)) return null;
            $stmts = $node->stmts;
            $n = count($stmts);
            for ($i = 0; $i < $n - 1; $i++) {
                $sw = $stmts[$i];
                if (!($sw instanceof Stmt\Switch_)) continue;
                $nextLabel = null;
                $nextLabelIdx = null;
                for ($j = $i + 1; $j < $n; $j++) {
                    if ($stmts[$j] instanceof Stmt\Label) {
                        $nextLabel = $stmts[$j]->name->toString();
                        $nextLabelIdx = $j;
                        break;
                    }
                    // If there are non-label stmts between switch and label, bail.
                    break;
                }
                if ($nextLabel === null) continue;

                // Count how many times nextLabel is referenced inside the switch.
                $refsInside = 0;
                $walker = new NodeTraverser();
                $walker->addVisitor(new class($nextLabel, $refsInside) extends NodeVisitorAbstract {
                    private string $l; private int $c;
                    public function __construct(string $l, int &$c) { $this->l = $l; $this->c =& $c; }
                    public function enterNode(Node $n) {
                        if ($n instanceof Stmt\Goto_ && $n->name->toString() === $this->l) $this->c++;
                    }
                });
                $walker->traverse($sw->cases);

                // Count outside references (elsewhere in parent stmts).
                $refsOutside = 0;
                foreach ($stmts as $idx => $s) {
                    if ($idx === $i) continue; // skip the switch itself; already counted
                    $w = new NodeTraverser();
                    $w->addVisitor(new class($nextLabel, $refsOutside) extends NodeVisitorAbstract {
                        private string $l; private int $c;
                        public function __construct(string $l, int &$c) { $this->l = $l; $this->c =& $c; }
                        public function enterNode(Node $n) {
                            if ($n instanceof Stmt\Goto_ && $n->name->toString() === $this->l) $this->c++;
                        }
                    });
                    $w->traverse([$s]);
                }

                if ($refsInside < 1) continue;

                // Rewrite every `goto nextLabel` inside the switch to a break.
                $rewriter = new NodeTraverser();
                $rewriter->addVisitor(new class($nextLabel) extends NodeVisitorAbstract {
                    private string $l;
                    public function __construct(string $l) { $this->l = $l; }
                    public function leaveNode(Node $n) {
                        if ($n instanceof Stmt\Goto_ && $n->name->toString() === $this->l) {
                            return new Stmt\Break_();
                        }
                        return null;
                    }
                });
                $sw->cases = $rewriter->traverse($sw->cases);
                $this->changed = true;

                // If no outside references remain, remove the label itself.
                if ($refsOutside === 0) {
                    array_splice($stmts, $nextLabelIdx, 1);
                    $node->stmts = $stmts;
                }
                return null;
            }
            return null;
        }
    });
    $t->traverse($ast);
    return $changed;
}

/**
 * Reconstruct while/do-while loops from label+if+goto patterns.
 *
 * While form:
 *   L:
 *     if (C) { body; goto L; }     → while (C) { body; }
 *
 * Do-while form:
 *   L: body1; if (C) goto L;       → do { body1; } while (C);
 */
function whileLoopReconstructPass(array &$ast): bool
{
    $changed = false;
    $t = new NodeTraverser();
    $t->addVisitor(new class($changed) extends NodeVisitorAbstract {
        private bool $changed;
        public function __construct(bool &$c) { $this->changed =& $c; }
        public function leaveNode(Node $node) {
            if (!isset($node->stmts) || !is_array($node->stmts)) return null;
            $stmts = $node->stmts;
            $n = count($stmts);
            $refs = $this->gotoRefs($stmts);

            for ($i = 0; $i < $n - 1; $i++) {
                $lbl = $stmts[$i];
                if (!($lbl instanceof Stmt\Label)) continue;
                $lname = $lbl->name->toString();

                // While form: L: if (C) { body...; goto L; }
                $next = $stmts[$i + 1];
                if ($next instanceof Stmt\If_
                    && !$next->else && !$next->elseifs
                    && $next->stmts) {
                    $ifBody = $next->stmts;
                    $bodyLast = end($ifBody);
                    if ($bodyLast instanceof Stmt\Goto_ && $bodyLast->name->toString() === $lname) {
                        $bodyCopy = $ifBody;
                        array_pop($bodyCopy); // drop goto L

                        $consumedRefs = 1;
                        if (($refs[$lname] ?? 0) === $consumedRefs) {
                            $while = new Stmt\While_($next->cond, $bodyCopy);
                            array_splice($stmts, $i, 2, [$while]);
                            $node->stmts = $stmts;
                            $this->changed = true;
                            return null;
                        }
                    }
                }

                // Do-while form: L: body1; if (C) goto L;
                // Search forward for an If_ with a single goto-L body.
                $endIdx = null;
                for ($j = $i + 1; $j < $n; $j++) {
                    $s = $stmts[$j];
                    if ($s instanceof Stmt\Label) break; // other label intervenes
                    if ($s instanceof Stmt\If_ && !$s->else && !$s->elseifs
                        && count($s->stmts) === 1
                        && $s->stmts[0] instanceof Stmt\Goto_
                        && $s->stmts[0]->name->toString() === $lname) {
                        $endIdx = $j;
                        break;
                    }
                }
                if ($endIdx !== null) {
                    $body = array_slice($stmts, $i + 1, $endIdx - $i - 1);
                    $cond = $stmts[$endIdx]->cond;
                    if (($refs[$lname] ?? 0) === 1) {
                        $do = new Stmt\Do_($cond, $body);
                        array_splice($stmts, $i, $endIdx - $i + 1, [$do]);
                        $node->stmts = $stmts;
                        $this->changed = true;
                        return null;
                    }
                }
            }
            return null;
        }

        private function gotoRefs(array $stmts): array {
            $c = [];
            $t = new NodeTraverser();
            $t->addVisitor(new class($c) extends NodeVisitorAbstract {
                private array $c;
                public function __construct(array &$c) { $this->c =& $c; }
                public function enterNode(Node $n) {
                    if ($n instanceof Stmt\Goto_) {
                        $name = $n->name->toString();
                        $this->c[$name] = ($this->c[$name] ?? 0) + 1;
                    }
                }
            });
            $t->traverse($stmts);
            return $c;
        }
    });
    $t->traverse($ast);
    return $changed;
}

/**
 * Rewrite goto-to-end-of-loop-body as `continue`, and goto-to-label-after-loop
 * as `break`, for for/foreach/while/do-while loops.
 */
function loopBreakContinuePass(array &$ast): bool
{
    $changed = false;
    $t = new NodeTraverser();
    $t->addVisitor(new class($changed) extends NodeVisitorAbstract {
        private bool $changed;
        public function __construct(bool &$c) { $this->changed =& $c; }
        public function leaveNode(Node $node) {
            if (!isset($node->stmts) || !is_array($node->stmts)) return null;
            $stmts = $node->stmts;
            $n = count($stmts);
            for ($i = 0; $i < $n; $i++) {
                $loop = $stmts[$i];
                if (!($loop instanceof Stmt\Foreach_) && !($loop instanceof Stmt\For_)
                    && !($loop instanceof Stmt\While_) && !($loop instanceof Stmt\Do_)) {
                    continue;
                }
                if (!is_array($loop->stmts) || !$loop->stmts) continue;

                // "continue" target: a label that appears at the END of the loop body.
                $continueTargets = [];
                $last = end($loop->stmts);
                $k = count($loop->stmts) - 1;
                while ($k >= 0 && $loop->stmts[$k] instanceof Stmt\Label) {
                    $continueTargets[$loop->stmts[$k]->name->toString()] = true;
                    $k--;
                }

                // "break" target: a Label immediately after the loop in parent stmts.
                $breakTargets = [];
                $breakLabelIdx = null;
                if ($i + 1 < $n && $stmts[$i + 1] instanceof Stmt\Label) {
                    $breakTargets[$stmts[$i + 1]->name->toString()] = true;
                    $breakLabelIdx = $i + 1;
                }

                if (!$continueTargets && !$breakTargets) continue;

                // Replace gotos within this loop body (not nested sub-loops, which have their own).
                $localChanged = false;
                $rewriter = new NodeTraverser();
                $rewriter->addVisitor(new class($continueTargets, $breakTargets, $localChanged) extends NodeVisitorAbstract {
                    private array $c; private array $b; private bool $ch;
                    public function __construct(array $c, array $b, bool &$ch) {
                        $this->c = $c; $this->b = $b; $this->ch =& $ch;
                    }
                    public function enterNode(Node $n) {
                        // Don't descend into nested loops/closures - their gotos refer to
                        // their own enclosing targets.
                        if ($n instanceof Stmt\Foreach_ || $n instanceof Stmt\For_
                            || $n instanceof Stmt\While_ || $n instanceof Stmt\Do_
                            || $n instanceof Expr\Closure || $n instanceof Stmt\Function_
                            || $n instanceof Stmt\ClassMethod) {
                            return NodeTraverser::DONT_TRAVERSE_CHILDREN;
                        }
                        return null;
                    }
                    public function leaveNode(Node $n) {
                        if ($n instanceof Stmt\Goto_) {
                            $name = $n->name->toString();
                            if (isset($this->c[$name])) {
                                $this->ch = true;
                                return new Stmt\Continue_();
                            }
                            if (isset($this->b[$name])) {
                                $this->ch = true;
                                return new Stmt\Break_();
                            }
                        }
                        return null;
                    }
                });
                $loop->stmts = $rewriter->traverse($loop->stmts);

                if ($localChanged) {
                    $this->changed = true;

                    // If no references remain to continue/break targets at this scope,
                    // drop those labels.
                    $refs = [];
                    $w = new NodeTraverser();
                    $w->addVisitor(new class($refs) extends NodeVisitorAbstract {
                        private array $r;
                        public function __construct(array &$r) { $this->r =& $r; }
                        public function enterNode(Node $n) {
                            if ($n instanceof Stmt\Goto_) $this->r[$n->name->toString()] = true;
                        }
                    });
                    $w->traverse($node->stmts);

                    // Drop trailing continue-target labels if unused.
                    while ($loop->stmts) {
                        $endLbl = end($loop->stmts);
                        if ($endLbl instanceof Stmt\Label
                            && !isset($refs[$endLbl->name->toString()])) {
                            array_pop($loop->stmts);
                        } else break;
                    }

                    // Drop the break-target label if unused.
                    if ($breakLabelIdx !== null) {
                        $bname = $stmts[$breakLabelIdx]->name->toString();
                        if (!isset($refs[$bname])) {
                            array_splice($stmts, $breakLabelIdx, 1);
                            $node->stmts = $stmts;
                        }
                    }
                }
                return null;
            }
            return null;
        }
    });
    $t->traverse($ast);
    return $changed;
}

/**
 * Strip statements between a terminator (Return_/Throw_/Break_/Continue_/Goto_)
 * and the next label. These are unreachable.
 */
function stripDeadCodePass(array &$ast): bool
{
    $changed = false;
    $t = new NodeTraverser();
    $t->addVisitor(new class($changed) extends NodeVisitorAbstract {
        private bool $changed;
        public function __construct(bool &$c) { $this->changed =& $c; }
        public function leaveNode(Node $node) {
            if (!isset($node->stmts) || !is_array($node->stmts)) return null;
            $out = [];
            $dead = false;
            foreach ($node->stmts as $s) {
                if ($dead) {
                    if ($s instanceof Stmt\Label) { $dead = false; $out[] = $s; }
                    else { $this->changed = true; }
                    continue;
                }
                $out[] = $s;
                if ($s instanceof Stmt\Return_ || $s instanceof Stmt\Throw_
                    || $s instanceof Stmt\Break_ || $s instanceof Stmt\Continue_
                    || $s instanceof Stmt\Goto_) {
                    $dead = true;
                }
            }
            $node->stmts = $out;
            return null;
        }
    });
    $t->traverse($ast);
    return $changed;
}

/** Strip trailing `goto X; X:` pairs left by earlier passes. */
function stripRedundantGotosPass(array &$ast): bool
{
    $changed = false;
    $t = new NodeTraverser();
    $t->addVisitor(new class($changed) extends NodeVisitorAbstract {
        private bool $changed;
        public function __construct(bool &$c) { $this->changed =& $c; }
        public function leaveNode(Node $node) {
            if (!isset($node->stmts) || !is_array($node->stmts)) return null;
            $out = [];
            $n = count($node->stmts);
            for ($i = 0; $i < $n; $i++) {
                $s = $node->stmts[$i];
                if ($s instanceof Stmt\Goto_ && $i + 1 < $n
                    && $node->stmts[$i + 1] instanceof Stmt\Label
                    && $node->stmts[$i + 1]->name->toString() === $s->name->toString()) {
                    $this->changed = true;
                    continue;
                }
                $out[] = $s;
            }
            $node->stmts = $out;
            return null;
        }
    });
    $t->traverse($ast);
    return $changed;
}

function deobfuscateFile(string $path): bool
{
    $code = file_get_contents($path);
    if ($code === false) return false;

    $parser = (new ParserFactory())->create(ParserFactory::PREFER_PHP7);
    try { $ast = $parser->parse($code); }
    catch (\Throwable $e) {
        fwrite(STDERR, "Parse error in $path: " . $e->getMessage() . "\n");
        return false;
    }
    if ($ast === null) return false;

    // Pass 1: linearize.
    $t = new NodeTraverser();
    $t->addVisitor(new GotoLinearizer());
    $ast = $t->traverse($ast);

    // Fixed-point: dead-label removal, label hops, if folds, trailing gotos.
    for ($iter = 0; $iter < 20; $iter++) {
        $changed = false;

        $t = new NodeTraverser();
        $t->addVisitor(new DeadLabelRemover());
        $ast = $t->traverse($ast);

        if (labelHopCollapse($ast)) $changed = true;
        if (stripRedundantGotosPass($ast)) $changed = true;
        if (stripDeadCodePass($ast)) $changed = true;
        if (switchBreakPass($ast)) $changed = true;
        if (whileLoopReconstructPass($ast)) $changed = true;
        if (loopBreakContinuePass($ast)) $changed = true;
        if (ifGotoFold($ast)) $changed = true;

        if (!$changed) break;
    }

    // Final dead-label pass.
    $t = new NodeTraverser();
    $t->addVisitor(new DeadLabelRemover());
    $ast = $t->traverse($ast);

    $printer = new PrettyPrinter\Standard();
    file_put_contents($path, $printer->prettyPrintFile($ast));
    return true;
}

function walk(string $path): array
{
    $r = [];
    if (is_file($path)) { if (substr($path, -4) === '.php') $r[] = $path; return $r; }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path));
    foreach ($it as $f) { if ($f->isFile() && $f->getExtension() === 'php') $r[] = $f->getPathname(); }
    return $r;
}

array_shift($argv);
if (!$argv) { fwrite(STDERR, "Usage: deobfuscate.php <path> [...]\n"); exit(1); }

$files = [];
foreach ($argv as $a) $files = array_merge($files, walk($a));

$total = count($files);
$ok = 0;
$fail = 0;
foreach ($files as $i => $f) {
    printf("[%d/%d] %s\n", $i + 1, $total, $f);
    if (deobfuscateFile($f)) $ok++; else $fail++;
}
printf("\nDone. %d succeeded, %d failed.\n", $ok, $fail);
