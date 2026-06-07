<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap/unit.php';

// glpichat_upgrade_right_bits depends on GLPI's READ/CREATE/UPDATE integer constants.
// Define them here so the function can be loaded without a full GLPI bootstrap.
if (!defined('READ')) {
    define('READ', 1);
}
if (!defined('UPDATE')) {
    define('UPDATE', 2);
}
if (!defined('CREATE')) {
    define('CREATE', 4);
}

require_once dirname(__DIR__, 3) . '/src/db/setup_helpers.php';

// ---------------------------------------------------------------------------
// F2 — glpichat_upgrade_right_bits must NOT promote a zero bitmask to the
// default mask.  A zero in an existing row means an admin explicitly revoked
// access; silently restoring it would bypass that intent.
// ---------------------------------------------------------------------------

glpichat_unit_assert_same(
    0,
    glpichat_upgrade_right_bits('plugin_glpichat_public', 0, READ | CREATE),
    'F2: zero bitmask is not promoted to the default mask'
);
glpichat_unit_pass('F2: zero bitmask is not promoted to the default mask');

// ---------------------------------------------------------------------------
// F2 — fix-write-without-read: a profile that has CREATE but lacks READ must
// have READ added so that Session::haveRight(..., READ) returns true.
// ---------------------------------------------------------------------------

glpichat_unit_assert_same(
    CREATE | READ,
    glpichat_upgrade_right_bits('plugin_glpichat_public', CREATE, READ | CREATE),
    'F2: CREATE-only bitmask is upgraded to include READ'
);
glpichat_unit_pass('F2: CREATE-only bitmask is upgraded to include READ');

glpichat_unit_assert_same(
    UPDATE | READ,
    glpichat_upgrade_right_bits('plugin_glpichat_internal', UPDATE, READ | UPDATE),
    'F2: UPDATE-only bitmask is upgraded to include READ'
);
glpichat_unit_pass('F2: UPDATE-only bitmask is upgraded to include READ');

// ---------------------------------------------------------------------------
// Idempotency: a bitmask that already carries READ must not be changed.
// ---------------------------------------------------------------------------

glpichat_unit_assert_same(
    READ | CREATE,
    glpichat_upgrade_right_bits('plugin_glpichat_public', READ | CREATE, READ | CREATE),
    'F2: bitmask with READ already set is returned unchanged'
);
glpichat_unit_pass('F2: bitmask with READ already set is returned unchanged');

glpichat_unit_assert_same(
    READ | CREATE | UPDATE,
    glpichat_upgrade_right_bits('plugin_glpichat_invite', READ | CREATE | UPDATE, READ | CREATE | UPDATE),
    'F2: full bitmask is returned unchanged'
);
glpichat_unit_pass('F2: full bitmask is returned unchanged');

// ---------------------------------------------------------------------------
// Rights not in the upgrade allowlist must be returned untouched regardless
// of their bitmask value.
// ---------------------------------------------------------------------------

glpichat_unit_assert_same(
    CREATE,
    glpichat_upgrade_right_bits('plugin_glpichat_unknown_right', CREATE, READ | CREATE),
    'F2: right not in allowlist is returned as-is (no upgrade applied)'
);
glpichat_unit_pass('F2: right not in allowlist is returned as-is (no upgrade applied)');

glpichat_unit_assert_same(
    0,
    glpichat_upgrade_right_bits('plugin_glpichat_unknown_right', 0, READ | CREATE),
    'F2: zero bitmask for unlisted right is also returned as-is'
);
glpichat_unit_pass('F2: zero bitmask for unlisted right is also returned as-is');

// ---------------------------------------------------------------------------
// All canonical right names that are in the upgrade allowlist must honour
// the zero-no-promotion rule.
// ---------------------------------------------------------------------------

$allowlisted_rights = [
    'plugin_glpichat_public',
    'plugin_glpichat_internal',
    'plugin_glpichat_invite',
    'plugin_glpichat_view_audit',
    'plugin_glpichat_admin',
];

foreach ($allowlisted_rights as $right_name) {
    glpichat_unit_assert_same(
        0,
        glpichat_upgrade_right_bits($right_name, 0, READ | CREATE),
        'F2: zero bitmask is not promoted for allowlisted right: ' . $right_name
    );
    glpichat_unit_pass('F2: zero bitmask is not promoted for allowlisted right: ' . $right_name);
}

fwrite(STDOUT, "[PASS] Rights model unit tests completed\n");
