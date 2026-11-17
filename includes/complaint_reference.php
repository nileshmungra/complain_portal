<?php

function formatComplaintReference(int $complaintId, ?string $publicReference = null): string
{
    return is_string($publicReference) && $publicReference !== ''
        ? $publicReference
        : 'CMP-' . str_pad((string) $complaintId, 6, '0', STR_PAD_LEFT);
}

function getComplaintStateCode(?string $state, string $district): ?string
{
    if (is_string($state) && trim($state) !== '') {
        $normalizedState = strtoupper(preg_replace('/[^A-Za-z]/', '', trim($state)) ?? '');
        if (strlen($normalizedState) >= 3) {
            return substr($normalizedState, 0, 3);
        }
    }

    $gujaratDistricts = ['Rajkot', 'Junagadh', 'Jamnagar', 'Amreli'];
    return in_array($district, $gujaratDistricts, true) ? 'GUJ' : null;
}

function getNextComplaintSequence(mysqli $conn, string $stateCode): int
{
    if (!preg_match('/\A[A-Z]{3}\z/', $stateCode)) {
        throw new InvalidArgumentException('A valid three-letter state code is required.');
    }

    $statement = $conn->prepare(
        'INSERT INTO complaint_id_counters (state_code, last_sequence)
         VALUES (?, LAST_INSERT_ID(1))
         ON DUPLICATE KEY UPDATE last_sequence = LAST_INSERT_ID(last_sequence + 1)'
    );
    $statement->bind_param('s', $stateCode);
    $statement->execute();

    $result = (int) $conn->query('SELECT LAST_INSERT_ID()')->fetch_row()[0];
    if ($result < 1) {
        throw new RuntimeException('Unable to allocate a complaint reference sequence.');
    }

    return $result;
}

function buildComplaintReference(string $stateCode, string $dayMonth, int $sequence): string
{
    if (!preg_match('/\A[A-Z]{3}\z/', $stateCode) || !preg_match('/\A\d{4}\z/', $dayMonth) || $sequence < 1) {
        throw new InvalidArgumentException('Complaint reference components are invalid.');
    }

    return $stateCode . '-' . $dayMonth . '-' . str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
}
