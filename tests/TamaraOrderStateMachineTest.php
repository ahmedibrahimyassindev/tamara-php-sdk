<?php

namespace Tamara\Tests;

use Tamara\Security\TamaraOrderStateMachine;

class TamaraOrderStateMachineTest
{
    public function run(): void
    {
        $this->testAllowedTransitions();
        $this->testTerminalStatesCannotBeOverwritten();
        $this->testOutofOrderTransitionsBlocked();
        $this->testIdempotentTransitionsAllowed();
    }

    protected function testAllowedTransitions(): void
    {
        // new -> approved -> authorised -> fully_captured
        assert(TamaraOrderStateMachine::canTransition('new', 'approved'));
        assert(TamaraOrderStateMachine::canTransition('approved', 'authorised'));
        assert(TamaraOrderStateMachine::canTransition('authorised', 'fully_captured'));
        assert(TamaraOrderStateMachine::canTransition('fully_captured', 'refunded'));
    }

    protected function testTerminalStatesCannotBeOverwritten(): void
    {
        // Terminal states cannot transition to anything
        assert(!TamaraOrderStateMachine::canTransition('declined', 'approved'));
        assert(!TamaraOrderStateMachine::canTransition('cancelled', 'authorised'));
        assert(!TamaraOrderStateMachine::canTransition('expired', 'approved'));
        assert(!TamaraOrderStateMachine::canTransition('refunded', 'captured'));
    }

    protected function testOutofOrderTransitionsBlocked(): void
    {
        // Older events arriving after newer states must be blocked
        assert(!TamaraOrderStateMachine::canTransition('fully_captured', 'approved'));
        assert(!TamaraOrderStateMachine::canTransition('fully_captured', 'authorised'));
        assert(!TamaraOrderStateMachine::canTransition('fully_captured', 'cancelled'));
    }

    protected function testIdempotentTransitionsAllowed(): void
    {
        assert(TamaraOrderStateMachine::canTransition('authorised', 'authorised'));
        assert(TamaraOrderStateMachine::canTransition('fully_captured', 'captured'));
    }
}

