Philippine Standard Geographic Code (PSGC) reference data

Source: PSA PSGC 2Q 2026 Publication Datafile, PSGC worksheet. The workbook
was mirrored by the independent mrkiyn/psgc-api project:
https://github.com/mrkiyn/psgc-api/blob/main/data/raw/PSGC-2Q-2026-Publication-Datafile.xlsx

The four JSON files are a reduced extract of the workbook's 10-digit PSGC,
Name, and Geographic Level columns. Parent relationships use the official
geographic levels and PSGC codes. Barangays are linked to their
city/municipality/sub-municipality by the seven-digit parent code. Counts: 18
regions, 82 provinces, 149 cities, 1,493 municipalities, 14
sub-municipalities, and 42,010 barangays.

NCR has no province-level PSGC entry; the API represents it as `NCR`.
Sub-municipalities are selectable so barangays such as those in Manila can
retain their full hierarchy. The 18 independent cities and eight BARMM
special-area municipalities without a province are grouped under their actual
PSGC region. Street names are not part of PSGC and are entered manually.
