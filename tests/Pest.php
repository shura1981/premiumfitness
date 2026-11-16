<?php

declare(strict_types=1);

/*
 | -----------------------------------------------------------------------------
 | Test Case
 | -----------------------------------------------------------------------------
 | Pest applies the default PHPUnit TestCase to every suite. Integration and
 | Feature tests can opt-in to helpers by `use`-ing functions defined below.
 */

pest()->extend(PHPUnit\Framework\TestCase::class)->in('Unit', 'Integration', 'E2E');

/*
 | -----------------------------------------------------------------------------
 | Expectations
 | -----------------------------------------------------------------------------
 */

/*
 | -----------------------------------------------------------------------------
 | Functions
 | -----------------------------------------------------------------------------
 */
