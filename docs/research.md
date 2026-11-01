# Algorithm and research notes

## TAMRA v1

TAMRA is an experimental greedy ranking, not an established novel or optimal method. All six components are in [0,1], and the versioned weights sum to one. Initial weights are urgency .25, distance .20, capacity .15, compatibility .15, pickup feasibility .15 and historical reliability .10.

Before ranking: donation status/deadline and current safety checks must pass; receiver must be active, available, category-compatible, within collection radius, with enough unreserved capacity and enough time to collect within its availability/deadline. Missing required inputs exclude a candidate rather than assigning favorable values.

- **Urgency:** `clamp(1 - remaining_minutes / urgency_horizon_minutes)`; initial horizon 1440 minutes.
- **Distance:** `clamp(1 - haversine_km / receiver_radius_km)`.
- **Capacity fit:** `min(1, donation_kg / free_capacity_kg)`, after the hard full-capacity constraint. It favors a tighter capacity fit.
- **Compatibility:** 1 for accepted category; incompatible candidates never enter ranking.
- **Feasibility:** positive time slack divided by the remaining collection window. Estimate uses straight-line km × 1.3 detour, configured motorized speed (25 km/h initially) or 4 km/h walking, plus 20 minutes handling. Collection begins no earlier than availability. High ≥ .6, medium ≥ .25, low otherwise. This is **not a calibrated probability**.
- **Reliability:** completed / (completed + failed + expired accepted pickups), using terminal history available at ranking time. Fewer than five observations uses neutral .5. Cancellation is excluded.

Tie-breaks use receiver ID. All recommendations preserve configuration version, parameters, inputs, six scores and explanation. Acceptance recomputes current eligibility and locks donation/receiver rows. A generated unique active-donation key is a second protection against double allocation.

Urgency is constant across receivers for a single donation and therefore does not change their relative ordering. This matters when interpreting the proposed weighted score. Independent ranking is not global optimization, fairness optimization, volunteer route planning or a calibrated outcome model. Transport speed and detour are assumptions, not live routing observations.

## Comparable experiment conditions

Four approaches use the same scenario, hard eligibility, capacity budget, outcome seed and feasibility baseline:

1. **Nearest:** listing creation order, nearest eligible receiver.
2. **Earliest deadline:** earliest-deadline donation order, then nearest eligible receiver.
3. **Highest capacity:** listing creation order, largest remaining suitable capacity.
4. **TAMRA:** listing creation order, highest weighted score.

Experiments use offline snapshot allocation: capacity remains committed across the entire run, including failed simulated assignments. There is no within-run capacity recycling or live dispatch simulation. Different sorting policies may see the same set of available inputs, but their greedy allocations differ. Never interpret these results as a field trial.

The generator uses a local deterministic LCG for scenario creation. Synthetic inputs use a fixed 2026-01-01 UTC scenario clock, category variation, geographic variation, transport availability, deadlines, capacities and declared rejections. A shared SHA-256-derived draw per (outcome seed, donation, receiver, feature) supplies route/delay and success variation. Synthetic latent reliability is generated independently of the recorded historical counts; imported scenarios use a documented .75 latent success assumption. The outcome model uses alternative travel assumptions (18 km/h motorized or 3.5 km/h walking) and a variable delay; it is hypothetical and is not learned from observations.

Measured execution time varies. Dataset generation, matching decisions and outcome metrics are reproducible for identical inputs/configuration/seeds. Repeat experiments with different outcome seeds to inspect means and sample standard deviations; repeated identical seeds are not independent evidence. The full JSON bundle contains the immutable input hash, dataset, configuration and decisions. Live donation/pickup tables are never changed by an experiment.

## Metrics and exclusions

- Eligible listed donations: scenario donations marked available; declared rejected records are excluded from all algorithms equally.
- Pickup success rate: successful simulated pickups / eligible accepted assignments. Undefined for zero assignments.
- Expired/uncollected rate: eligible donations not successfully collected / eligible listings; includes failed and unassigned records. The UI states this broader interpretation explicitly.
- Average distance: mean Haversine km over successful pickups only. Undefined when none succeed.
- Capacity utilization: all assigned kg / total initial receiver capacity.
- Average matching time: total algorithm execution ms / eligible listed donations; includes ranking/filtering and simulation overhead rather than measuring queue latency.
- Precision/recall/F1: classify feasibility ≥ .25 as a predicted success and compare with the later outcome. Undefined denominators yield null, not fabricated zeros.
- ROC-AUC: pairwise positive-vs-negative ranking, half credit for ties; undefined with only one outcome class.
- No Brier score is reported because the heuristic is not a calibrated probability.

Operational impact separately evaluates the match prediction stored at acceptance against subsequent completed/failed/expired outcomes. Cancellations and ongoing assignments are excluded. No trained classifier is supplied: logistic regression and time/group split evaluation require enough permitted labelled data. Do not train against future outcomes or tune using a held-out test set.

## Dataset metadata and import

Every dataset stores source, period, record count, preprocessing, missing-value policy, permission status, limitations and SHA-256 content hash. Supported sources are simulated, anonymized historical inputs, and permitted pilot inputs. Importing actual inputs still produces **simulated experiment outcomes**, not measured pilot results.

Only the following anonymous scenario structure is accepted; unlisted fields such as names, email and addresses are rejected. Use anonymous numeric IDs, generalized coordinates and non-identifying metadata; remove personal identifiers before import. All required fields must be present. Live operational data is not automatically exported into research.

```json
{
  "donations": [{
    "id": 1, "category_id": 2, "quantity_kg": 5,
    "latitude": 28.61, "longitude": 77.21,
    "created_at": "2026-01-01T08:00:00Z", "deadline": "2026-01-01T12:00:00Z",
    "status": "available"
  }],
  "receivers": [{
    "id": 1, "capacity_kg": 20, "latitude": 28.62, "longitude": 77.22,
    "collection_available": true, "transport_available": true, "max_distance_km": 30,
    "available_from": "2026-01-01T08:00:00Z", "available_until": "2026-01-01T18:00:00Z",
    "categories": [2], "history_count": 0, "history_success": 0
  }]
}
```

## Food-screening sources and limits

The screening flow requires hygienic/unserved/uncontaminated declarations, intact packaging, preparation/use-by/deadline traceability, ingredients/allergens and category-configured measurements. Unsafe declarations, damaged packaging and invalid/expired times reject food; missing category information holds it for correction. No admin can override a mandatory failure through matching.

Sources reviewed:

- [FSSAI Recovery and Distribution of Surplus Food Regulations, 2019](https://www.fssai.gov.in/upload/uploadfiles/files/Gazette_Notification_Surplus_Food_06_08_2019.pdf): primary basis for the surplus/unserved-food distinction, handling, packaging, traceability and consumption within shelf life.
- [FSSAI recovery-agency guidance](https://sharefood.eatrightindia.gov.in/pdf/Guidance-Document-For-Recovery-Agency.pdf): inspection, packaging, labeling and transport/storage context.

The default rule versions deliberately do not invent numerical shelf-life or temperature pass/fail thresholds. Recording temperature for prepared meals/dairy is a project information requirement, not a certification standard. Administrators can add category limits only with applicable guidance and a source URL; these need external domain validation before real operation. A recorded temperature/declaration does not establish food safety. Formal legal/regulatory compliance and field inspection are not replaced by the application.

## Initial literature review notes

These are starting references, not a systematic or complete novelty review. Only abstracts/publisher summaries were inspected where full text was unavailable; no unsupported experimental findings are asserted.

- [Dubey and Tanksale, multi-depot routing with time windows and split pickup/delivery](https://www.sciencedirect.com/science/article/pii/S095741742301309X), DOI 10.1016/j.eswa.2023.120807. Studies food recovery as a richer routing problem. TAMRA's pairwise ranking does not solve those multi-stop routing decisions.
- [Automating Food Drop: The Power of Two Choices for Dynamic and Fair Food Allocation](https://arxiv.org/abs/2406.06363). Relevant prior work on dynamic allocation and fairness. It prevents treating food-allocation matching itself as an unresearched problem.
- [Bandit Data-Driven Optimization for Crowdsourcing Food Rescue Platforms](https://ojs.aaai.org/index.php/AAAI/article/view/21475). Relevant prior work on data-driven food-rescue operations and engagement; the current transparent ranking is not an alternative validated bandit model.

A defensible project question is whether this particular transparent scoring policy changes collection outcomes and distance/capacity trade-offs relative to the defined baselines under disclosed conditions. It is not yet an established research gap or evidence of superiority. Further work requires fuller literature coverage, domain-reviewed safety policies, independent pilot observations with permission, time-aware validation and sensitivity/fairness analysis.
