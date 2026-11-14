# Agent Arena tests

All unit tests and test support code are located in `components/Base3/AgentArena/test/`.
Each PHP test or fixture file declares at most one named type. Production code
in `src/` is unchanged by the test-only patch.

## PHPUnit 11.5 and 12.5

Run the same test suite and the same configuration with either installed
version. There are no PHPUnit-specific compatibility branches.

From the BASE3 project root:

```sh
php vendor/bin/phpunit -c components/Base3/AgentArena/test/phpunit.xml
```

Or, when using a globally installed PHPUnit binary:

```sh
phpunit -c components/Base3/AgentArena/test/phpunit.xml
```

For explicit version verification, execute those commands once in a PHP
runtime with PHPUnit 11.5, and once with PHPUnit 12.5. The 12.5 runtime must
meet that release's PHP requirements. The tests use common supported mock,
assertion and process-isolation APIs.

## Project-wide PHPUnit suites

A host-wide runner such as `make test-issues` may discover these tests without
loading `components/Base3/AgentArena/test/phpunit.xml`. The four tests that rely on
`DIR_PLUGIN` therefore include the **same existing** `test/bootstrap.php` when
their test classes are loaded. This also works for PHPUnit's isolated API
processes and does not initialize an ILIAS request, create a new bootstrap,
or change production files.

The project-wide suite can independently report warnings from other plugins.
For example, an uninitialized `$_GET` in `Base3IliasLab` is outside this
AgentArena test patch and must be resolved in its owning test if needed.

## Coverage

For a real line-coverage report, enable Xdebug or PCOV and run:

```sh
XDEBUG_MODE=coverage php vendor/bin/phpunit -c components/Base3/AgentArena/test/phpunit.xml --coverage-text
XDEBUG_MODE=coverage php vendor/bin/phpunit -c components/Base3/AgentArena/test/phpunit.xml --coverage-html /tmp/agentarena-coverage
```

Coverage is counted only for `components/Base3/AgentArena/src/`, as configured in
`phpunit.xml`. The JavaScript UI regression smoke is separate from PHP line
coverage. The source-based game, service, repository, API, permissions, tools,
DI and migration tests aim for high coverage, but a percentage is only valid
when reported by the coverage driver on an actual PHPUnit run. No test is
silently skipped to inflate a percentage.

## Deterministic checks without PHPUnit

These already-shipped checks do not require a host or a model call:

```sh
php components/Base3/AgentArena/test/run.php
node components/Base3/AgentArena/test/Display/ArenaBoardInteractionSmoke.js
```

## Runtime boundaries

The tests use `IDatabase` mocks to check SQL ownership and compare-and-swap
semantics. These mocks are not substitutes for MySQL or a production concurrency
test. PHPUnit tests do not contact a model provider. A live ILIAS/browser
request, MissionBay tool execution and a real concurrent database load test
remain separate integration checks.
