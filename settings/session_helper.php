<?php
// ============================================================================
// ECOTWIN — Session & Auth Helpers
// ============================================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Returns the currently logged-in user array, or null if not logged in.
 */
function getCurrentUser(): ?array {
    return $_SESSION['user'] ?? null;
}

/**
 * Requires the user to be logged in; redirects to login page otherwise.
 */
function requireLogin(): void {
    if (!isset($_SESSION['user'])) {
        header('Location: ../login.php');
        exit;
    }
}

/**
 * Returns true if the current user has admin role.
 */
function isAdmin(): bool {
    return ($_SESSION['user']['role'] ?? '') === 'admin';
}

/**
 * Returns a user-friendly role label.
 */
function roleLabel(string $role): string {
    return match ($role) {
        'admin'      => 'Admin',
        'researcher' => 'Researcher',
        default      => ucfirst($role),
    };
}

/**
 * Returns the CSS badge class for a given role.
 */
function roleBadgeClass(string $role): string {
    return match ($role) {
        'admin'      => 'badge-success',
        'researcher' => 'badge-info',
        default      => 'badge-neutral',
    };
}

/**
 * Returns initials from a full name (e.g., "Dr. Jane Smith" → "JS").
 */
function getInitials(string $name): string {
    $words = preg_split('/\s+/', preg_replace('/^(Dr\.|Prof\.|Mr\.|Ms\.|Mrs\.)\s*/i', '', trim($name)));
    $initials = '';
    foreach ($words as $word) {
        if ($word !== '') $initials .= strtoupper($word[0]);
        if (strlen($initials) >= 2) break;
    }
    return $initials ?: '??';
}
