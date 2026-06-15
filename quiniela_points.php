<?php
declare(strict_types=1);

function quiniela_outcome(?int $score1, ?int $score2): ?int
{
    if ($score1 === null || $score2 === null) {
        return null;
    }

    return $score1 <=> $score2;
}

function calculate_quiniela_points(?int $predEq1, ?int $predEq2, ?int $actualEq1, ?int $actualEq2): int
{
    if ($predEq1 === null || $predEq2 === null || $actualEq1 === null || $actualEq2 === null) {
        return 0;
    }

    if ($predEq1 === $actualEq1 && $predEq2 === $actualEq2) {
        return 10;
    }

    $predictedOutcome = quiniela_outcome($predEq1, $predEq2);
    $actualOutcome = quiniela_outcome($actualEq1, $actualEq2);

    if ($predictedOutcome === null || $actualOutcome === null) {
        return 0;
    }

    if ($predictedOutcome === 0 || $actualOutcome === 0) {
        if ($predictedOutcome === $actualOutcome) {
            return 5;
        }

        return ($predEq1 === $actualEq1 || $predEq2 === $actualEq2) ? 1 : 0;
    }

    $points = 0;
    if ($predictedOutcome === $actualOutcome) {
        $points += 5;
    }

    $winnerKey = $actualOutcome > 0 ? 'eq1' : 'eq2';
    $loserKey = $actualOutcome > 0 ? 'eq2' : 'eq1';
    $predictedScores = [
        'eq1' => $predEq1,
        'eq2' => $predEq2,
    ];
    $actualScores = [
        'eq1' => $actualEq1,
        'eq2' => $actualEq2,
    ];

    if ($predictedScores[$winnerKey] === $actualScores[$winnerKey]) {
        $points += 3;
    }

    if ($predictedScores[$loserKey] === $actualScores[$loserKey]) {
        $points += 2;
    }

    return $points;
}
