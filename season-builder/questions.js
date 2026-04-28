// Global form guard (server-side rules mirrored)
function validateForm(e) {
    // Q1: total must be exactly 10, each 0–4
    var totalPoints = 0;
    var q1Inputs = document.querySelectorAll('input[name^="q1["]');
    for (var i = 0; i < q1Inputs.length; i++) {
        var val = parseInt(q1Inputs[i].value || '0', 10);
        if (val < 0 || val > 4) {
            alert("Each Question 1 choice must be between 0 and 4 points.");
            if (e) e.preventDefault();
            return false;
        }
        totalPoints += val;
    }
    if (totalPoints !== 10) {
        alert("You must allocate exactly 10 points in Question 1 (you have " + totalPoints + ").");
        if (e) e.preventDefault();
        return false;
    }

    // Q2: exactly 2 choices per part (now only parts 1 and 2)
    for (var q = 1; q <= 2; q++) {
        var q2Checks = document.querySelectorAll('input[name="q2[' + q + '][]"]:checked');
        if (q2Checks.length !== 2) {
            alert("For Question 2, part " + q + " you must choose exactly 2 options.");
            if (e) e.preventDefault();
            return false;
        }
    }

    // Q3: exactly 2 choices total
    var q3Checks = document.querySelectorAll('input[name="q3[]"]:checked');
    if (q3Checks.length !== 2) {
        alert("For Question 3, select exactly 2 options.");
        if (e) e.preventDefault();
        return false;
    }

    return true;
}

// --- Q1 helpers ---
function computeQ1Total() {
    var totalPoints = 0;
    var q1Inputs = document.querySelectorAll('input[name^="q1["]');
    for (var i = 0; i < q1Inputs.length; i++) {
        var val = parseInt(q1Inputs[i].value || '0', 10);
        if (!isNaN(val)) totalPoints += val;
    }
    return totalPoints;
}

function updateQ1UI() {
    var total = computeQ1Total();
    var totalEl = document.getElementById('q1_total');
    if (totalEl) {
        totalEl.textContent = total + " / 10";

        // Pulse effect on change
        totalEl.classList.remove('changed');
        setTimeout(function () {
            totalEl.classList.add('changed');
        }, 10);
    }

    // Enable "Next" only when total = 10
    var nextBtn = document.getElementById('next-step-1');
    if (nextBtn) {
        nextBtn.disabled = (total !== 10);
    }

    // For each category, disable "+" if that category is at 4 OR total is 10
    var controls = document.querySelectorAll('.points-control');
    for (var i = 0; i < controls.length; i++) {
        var control = controls[i];
        var catIndex = control.getAttribute('data-cat');
        var hiddenInput = document.getElementById('q1-hidden-' + catIndex);
        var plusBtn = control.querySelector('.points-btn.plus');
        if (!hiddenInput || !plusBtn) continue;

        var val = parseInt(hiddenInput.value || '0', 10);
        if (isNaN(val)) val = 0;

        if (val >= 4 || total >= 10) {
            plusBtn.disabled = true;
        } else {
            plusBtn.disabled = false;
        }
    }
}

// --- Q2 helpers ---
function updateQ2PartState(qNum) {
    var boxes = document.querySelectorAll('input[name="q2[' + qNum + '][]"]');
    var checkedCount = 0;
    for (var i = 0; i < boxes.length; i++) {
        if (boxes[i].checked) checkedCount++;
    }

    // Disable unchecked options once 2 are selected; re-enable if <2
    for (var j = 0; j < boxes.length; j++) {
        if (checkedCount >= 2) {
            if (!boxes[j].checked) {
                boxes[j].disabled = true;
            } else {
                boxes[j].disabled = false;
            }
        } else {
            boxes[j].disabled = false;
        }
    }

    // Update counter
    var counter = document.getElementById('q2-counter-' + qNum);
    if (counter) {
        counter.textContent = checkedCount + " / 2";
    }

    // Map to the correct "Next" button (only parts 1 and 2)
    var nextIdMap = {
        1: 'next-step-2',
        2: 'next-step-3'
    };
    var nextBtnId = nextIdMap[qNum];
    if (nextBtnId) {
        var btn = document.getElementById(nextBtnId);
        if (btn) {
            btn.disabled = (checkedCount !== 2);
        }
    }
}

// --- Q3 helpers ---
function updateQ3State() {
    var boxes = document.querySelectorAll('input[name="q3[]"]');
    var checkedCount = 0;
    for (var i = 0; i < boxes.length; i++) {
        if (boxes[i].checked) checkedCount++;
    }

    // Disable remaining options once 2 are selected; re-enable if <2
    for (var j = 0; j < boxes.length; j++) {
        if (checkedCount >= 2) {
            if (!boxes[j].checked) {
                boxes[j].disabled = true;
            } else {
                boxes[j].disabled = false;
            }
        } else {
            boxes[j].disabled = false;
        }
    }

    var counter = document.getElementById('q3-counter');
    if (counter) {
        counter.textContent = checkedCount + " / 2";
    }

    var submitBtn = document.getElementById('submit-button');
    if (submitBtn) {
        submitBtn.disabled = (checkedCount !== 2);
    }
}

// Wizard + event wiring
document.addEventListener('DOMContentLoaded', function () {
    // Now 4 steps total: Q1, Q2 Part 1, Q2 Part 2, Q3
    var totalSteps = 4;
    var progressFill = document.querySelector('.progress-bar-fill');
    var stepLabel = document.getElementById('step-label');

    function showStep(step) {
        var steps = document.querySelectorAll('.step');
        for (var i = 0; i < steps.length; i++) {
            steps[i].classList.remove('current');
        }
        var activeStep = document.querySelector('.step[data-step="' + step + '"]');
        if (activeStep) {
            activeStep.classList.add('current');
        }

        if (stepLabel) {
            stepLabel.textContent = step + " of " + totalSteps;
        }
        if (progressFill) {
            var pct = (step / totalSteps) * 100;
            progressFill.style.width = pct + "%";
        }
    }

    // --- Q1 events: point picker ---
    var controls = document.querySelectorAll('.points-control');
    for (var i = 0; i < controls.length; i++) {
        (function (control) {
            var catIndex = control.getAttribute('data-cat');
            var hiddenInput = document.getElementById('q1-hidden-' + catIndex);
            var valueSpan = control.querySelector('.points-value');
            var minusBtn = control.querySelector('.points-btn.minus');
            var plusBtn = control.querySelector('.points-btn.plus');

            function getCurrent() {
                var v = parseInt(hiddenInput.value || '0', 10);
                return isNaN(v) ? 0 : v;
            }

            function setCurrent(v) {
                hiddenInput.value = String(v);
                valueSpan.textContent = v;
                updateQ1UI();
            }

            minusBtn.addEventListener('click', function () {
                var current = getCurrent();
                if (current <= 0) return;
                setCurrent(current - 1);
            });

            plusBtn.addEventListener('click', function () {
                var current = getCurrent();
                if (current >= 4) return; // max 4 per category

                // Check if adding 1 would exceed total 10
                var inputs = document.querySelectorAll('input[name^="q1["]');
                var totalOther = 0;
                for (var j = 0; j < inputs.length; j++) {
                    var input = inputs[j];
                    if (input === hiddenInput) continue;
                    var v = parseInt(input.value || '0', 10);
                    if (!isNaN(v)) totalOther += v;
                }
                var newTotal = totalOther + (current + 1);
                if (newTotal > 10) {
                    return; // don't allow going over 10
                }

                setCurrent(current + 1);
            });

            // Initialize display from hidden value (including pre-filled from DB)
            setCurrent(getCurrent());
        })(controls[i]);
    }

    // Make sure initial Q1 UI reflects any pre-filled values
    updateQ1UI();

    // --- Q2 events (parts 1 and 2 only) ---
    for (var q = 1; q <= 2; q++) {
        (function (qNum) {
            var boxes = document.querySelectorAll('input[name="q2[' + qNum + '][]"]');
            for (var i = 0; i < boxes.length; i++) {
                boxes[i].addEventListener('change', function () {
                    updateQ2PartState(qNum);
                });
            }
            // Initialize counters and button state based on pre-checked boxes
            updateQ2PartState(qNum);
        })(q);
    }

    // --- Q3 events ---
    var q3Boxes = document.querySelectorAll('input[name="q3[]"]');
    for (var k = 0; k < q3Boxes.length; k++) {
        q3Boxes[k].addEventListener('change', updateQ3State);
    }
    // Initialize Q3 state from any pre-checked boxes
    updateQ3State();

    // --- Navigation buttons ---
    var next1 = document.getElementById('next-step-1'); // step 1 -> 2
    var next2 = document.getElementById('next-step-2'); // step 2 -> 3
    var next3 = document.getElementById('next-step-3'); // step 3 -> 4

    var back2 = document.getElementById('back-step-2'); // step 2 -> 1
    var back3 = document.getElementById('back-step-3'); // step 3 -> 2
    var back4 = document.getElementById('back-step-4'); // step 4 -> 3

    if (next1) next1.addEventListener('click', function () { showStep(2); });
    if (next2) next2.addEventListener('click', function () { showStep(3); });
    if (next3) next3.addEventListener('click', function () { showStep(4); });

    if (back2) back2.addEventListener('click', function () { showStep(1); });
    if (back3) back3.addEventListener('click', function () { showStep(2); });
    if (back4) back4.addEventListener('click', function () { showStep(3); });

    // Initialize on step 1
    showStep(1);

    // Attach form submit validation
    var form = document.getElementById('ml_form');
    if (form) {
        form.addEventListener('submit', function (e) {
            if (!validateForm(e)) {
                // If invalid, jump user back to step 1 (safe default)
                showStep(1);
            }
        });
    }
});
