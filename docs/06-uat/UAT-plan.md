# User acceptance testing (UAT) plan

Rubric phase: System testing (12 points). This pack is ready to run; results are recorded in `UAT-results-template.md`.

## 1. Objective

Confirm that real users can complete the advertised journeys without help, and record their rating of usability, speed, and clarity. UAT is not a bug hunt by the developer; it is a structured observation of first-time users.

## 2. Participants — target 15

| Group | Target | Suggested recruitment |
| --- | --- | --- |
| Patients (general users) | 8 | Classmates, relatives, neighbours; mix of ages and phone habits |
| Doctors or clinic staff | 4 | Nursing/med-tech classmates, a clinician contact, clinic receptionist |
| Mixed/other (students, IT) | 3 | Classmates who can stress the UI and give technical feedback |

Minimum viable set is 15 completed score sheets. If a participant cannot finish a task, the developer may help **only after** noting the point of failure.

## 3. Roles

| Role | Person | Responsibility |
| --- | --- | --- |
| Facilitator | Developer | Introduces the system, reads the script, does not lead answers |
| Participant | Guest | Performs tasks, thinks aloud, scores |
| Recorder | Teammate or facilitator | Writes observations and timings |

If working solo, record the session with the participant's permission and score immediately afterwards.

## 4. Session outline (30–40 minutes)

1. **Consent and privacy (3 min).** Explain that the system is a school project, that they will use demo accounts, and that no real medical data should be entered. Sign the consent form.
2. **Context (3 min).** One-minute explanation of what GentleCare does. Do not demonstrate the steps.
3. **Tasks (20 min).** Patient track or doctor track per the script. Ask them to think aloud.
4. **Scoring (5 min).** Participant fills the 1–5 rating sheet.
5. **Debrief (5 min).** Three questions: what was confusing, what was missing, would you use it.

## 5. Environment

- Demo laptop with Apache and MariaDB running, `http://127.0.0.1/OB-GYN/`.
- Sample data freshly prepared: `php Backend/tests/prepare_demo.php`.
- Demo accounts from `Backend/README.md`.
- Registration requires a real inbox for the Brevo code; for UAT, use the prepared accounts or the local code switch with the participant's consent. **Revert the switch immediately after the session.**
- Backup plan: if the network is down, skip the live-registration task and mark it as not tested rather than faking it.

## 6. Tasks and pass criteria

| ID | Task | Pass when |
| --- | --- | --- |
| U1 | Log in as the sample patient | Dashboard opens without help |
| U2 | Find a doctor near a chosen area | Provider appears in list and on the map |
| U3 | Book the earliest available appointment | Confirmation shows correct doctor, date, time, location |
| U4 | Find the booking under "My appointments" | Appointment visible with status |
| U5 | Cancel the appointment | Status changes; slot becomes available again |
| U6 | Log in as the sample doctor | Doctor dashboard opens |
| U7 | Publish a 30-minute slot | Slot appears in the doctor's list with the right time |
| U8 | Open the appointment list and mark a visit complete | Status changes and the patient gets a notification |
| U9 | Log in as the clinic | Clinic dashboard opens and clearly says which views are planned |
| U10 | Find where to change a password or recover an account | Participant can state whether the feature exists |

U10 is deliberately a **discovery** task: "no password reset yet" is an acceptable, documented answer. It tests honesty of the UI, not a missing feature.

## 7. Rating sheet (per participant)

| Question | 1 | 2 | 3 | 4 | 5 |
| --- | --- | --- | --- | --- | --- |
| The system was easy to use | ☐ | ☐ | ☐ | ☐ | ☐ |
| I could find what I needed without help | ☐ | ☐ | ☐ | ☐ | ☐ |
| The screens responded quickly | ☐ | ☐ | ☐ | ☐ | ☐ |
| Wording and labels were clear | ☐ | ☐ | ☐ | ☐ | ☐ |
| I would use this system if it were available | ☐ | ☐ | ☐ | ☐ | ☐ |

1 = strongly disagree, 5 = strongly agree.

## 8. Acceptance criteria

- At least 15 completed rating sheets.
- Median ≥ 4 on every question.
- At least 12 of 15 participants complete U1–U5 without help.
- Zero open critical or high defects; every UAT defect logged with severity and status.
- At least one improvement implemented and re-tested from the feedback, or a written reason it was deferred.

## 9. Ethics and privacy

- No real patient data. Use sample accounts and fictional appointments.
- Record only the participant's first name or initials, role group, and scores.
- Do not photograph faces without permission; screenshots should show the app only.
- Participants may stop at any time and ask for their row to be deleted.

## 10. After the sessions

1. Transfer scores into `UAT-results-template.md`.
2. Compute medians and completion counts.
3. Triage defects with the severity table in the test plan.
4. Fix high defects, rerun the automated suite, then ask the affected participant to confirm the fix.
5. Copy the finished table into the test report and attach the signed consent forms.
