# UAT results

Status: **not yet executed**. Replace the placeholder rows with real sessions. Do not invent participants.

## Summary

| Metric | Target | Actual |
| --- | --- | --- |
| Completed sessions | 15 | 0 |
| U1–U5 completed without help | ≥ 12/15 | — |
| Median "easy to use" | ≥ 4 | — |
| Median "would use it" | ≥ 4 | — |
| Open critical defects | 0 | 0 |
| Open high defects | 0 | 0 |

## Participant results

Use one row per participant. Task columns: `Y` = completed without help, `H` = completed with help, `N` = could not complete. Ratings are 1–5 in the order: ease, findability, speed, clarity, would-use.

| ID | Group | U1 | U2 | U3 | U4 | U5 | U6 | U7 | U8 | U9 | U10 | Ease | Find | Speed | Clarity | Use | Notes |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| P01 | Patient |  |  |  |  |  |  |  |  |  |  |  |  |  |  |  |  |
| P02 | Patient |  |  |  |  |  |  |  |  |  |  |  |  |  |  |  |  |
| P03 | Patient |  |  |  |  |  |  |  |  |  |  |  |  |  |  |  |  |
| P04 | Patient |  |  |  |  |  |  |  |  |  |  |  |  |  |  |  |  |
| P05 | Patient |  |  |  |  |  |  |  |  |  |  |  |  |  |  |  |  |
| P06 | Patient |  |  |  |  |  |  |  |  |  |  |  |  |  |  |  |  |
| P07 | Patient |  |  |  |  |  |  |  |  |  |  |  |  |  |  |  |  |
| P08 | Patient |  |  |  |  |  |  |  |  |  |  |  |  |  |  |  |  |
| D01 | Doctor |  |  |  |  |  |  |  |  |  |  |  |  |  |  |  |  |
| D02 | Doctor |  |  |  |  |  |  |  |  |  |  |  |  |  |  |  |  |
| D03 | Clinic |  |  |  |  |  |  |  |  |  |  |  |  |  |  |  |  |
| D04 | Clinic |  |  |  |  |  |  |  |  |  |  |  |  |  |  |  |  |
| X01 | Mixed |  |  |  |  |  |  |  |  |  |  |  |  |  |  |  |  |
| X02 | Mixed |  |  |  |  |  |  |  |  |  |  |  |  |  |  |  |  |
| X03 | Mixed |  |  |  |  |  |  |  |  |  |  |  |  |  |  |  |  |

Participant groups: P = patient track, D = doctor/clinic track, X = mixed or technical reviewer.

## Defects found in UAT

| ID | Participant | Severity | Description | Status | Fix verified by |
| --- | --- | --- | --- | --- | --- |
| UAT-01 |  |  |  |  |  |
| UAT-02 |  |  |  |  |  |
| UAT-03 |  |  |  |  |  |

## Feedback themes

| Theme | Frequency | Action taken |
| --- | --- | --- |
|  |  |  |

## Signed forms

Attach or reference the signed consent forms here: `consent-forms/` (create the folder when collecting them; do not commit personal data to a public repository).

## How to fill this in

1. Run each session with `UAT-script.md`.
2. Copy the participant's ratings into the row.
3. Log every confusion that needed help as a defect, even if it is only wording.
4. Fix high-severity items, rerun `Backend/tests/slots_appointments.php`, then ask the participant to confirm.
5. Update the summary and paste the final numbers into `../05-test-report.md`.
