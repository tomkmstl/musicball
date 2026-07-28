function computeQ1Total() {
    var totalPoints = 0;
    var q1Step = document.querySelector('.step[data-question-type="q1"]');
    if (!q1Step) return 0;

    var q1Inputs = q1Step.querySelectorAll('input[name^="q1["]');
    for (var i = 0; i < q1Inputs.length; i++) {
        var val = parseInt(q1Inputs[i].value || '0', 10);
        if (!isNaN(val)) totalPoints += val;
    }
    return totalPoints;
}

function setStepActionEnabled(step, enabled) {
    if (!step) return;
    var action = step.querySelector('.wizard-next, button[type="submit"]');
    if (action) action.disabled = !enabled;
}

function stepNumber(step) {
    if (!step) return 1;
    var value = parseInt(step.getAttribute('data-step') || '1', 10);
    return isNaN(value) ? 1 : value;
}

function updateQ1UI() {
    var step = document.querySelector('.step[data-question-type="q1"]');
    if (!step) return;

    var total = computeQ1Total();
    var totalEl = step.querySelector('#q1_total');
    if (totalEl) {
        totalEl.textContent = total + ' / 10';
        totalEl.classList.remove('changed');
        setTimeout(function () { totalEl.classList.add('changed'); }, 10);
    }

    setStepActionEnabled(step, total === 10);

    var controls = step.querySelectorAll('.points-control');
    for (var i = 0; i < controls.length; i++) {
        var control = controls[i];
        var catIndex = control.getAttribute('data-cat');
        var hiddenInput = document.getElementById('q1-hidden-' + catIndex);
        var plusBtn = control.querySelector('.points-btn.plus');
        if (!hiddenInput || !plusBtn) continue;

        var val = parseInt(hiddenInput.value || '0', 10);
        if (isNaN(val)) val = 0;
        plusBtn.disabled = (val >= 4 || total >= 10);
    }
}

function updateQ2StepState(step) {
    if (!step) return;

    var boxes = step.querySelectorAll('[data-q2-choice]');
    var checkedCount = 0;
    for (var i = 0; i < boxes.length; i++) {
        if (boxes[i].checked) checkedCount++;
    }

    for (var j = 0; j < boxes.length; j++) {
        boxes[j].disabled = (checkedCount >= 2 && !boxes[j].checked);
    }

    var counter = step.querySelector('[data-q2-counter]');
    if (counter) counter.textContent = checkedCount + ' / 2';

    setStepActionEnabled(step, checkedCount === 2);
}

function updateSelectionStep(step, checkboxSelector, counterSelector) {
    if (!step) return;

    var required = parseInt(step.getAttribute('data-required-selections') || '1', 10);
    if (isNaN(required) || required < 1) required = 1;

    var boxes = step.querySelectorAll(checkboxSelector);
    var checkedCount = 0;
    for (var i = 0; i < boxes.length; i++) {
        if (boxes[i].checked) checkedCount++;
    }

    for (var j = 0; j < boxes.length; j++) {
        boxes[j].disabled = (checkedCount >= required && !boxes[j].checked);
    }

    var counter = step.querySelector(counterSelector);
    if (counter) counter.textContent = checkedCount + ' / ' + required;

    setStepActionEnabled(step, checkedCount === required);
}

function validateForm() {
    var q1Step = document.querySelector('.step[data-question-type="q1"]');
    if (q1Step) {
        var totalPoints = 0;
        var q1Inputs = q1Step.querySelectorAll('input[name^="q1["]');
        for (var i = 0; i < q1Inputs.length; i++) {
            var val = parseInt(q1Inputs[i].value || '0', 10);
            if (val < 0 || val > 4) {
                return {
                    ok: false,
                    step: stepNumber(q1Step),
                    message: 'Each User Submitted Round idea must be between 0 and 4 points.'
                };
            }
            totalPoints += val;
        }
        if (totalPoints !== 10) {
            return {
                ok: false,
                step: stepNumber(q1Step),
                message: 'You must allocate exactly 10 points across User Submitted Rounds.'
            };
        }
    }

    var q2Steps = document.querySelectorAll('.step[data-question-type="q2"]');
    for (var q = 0; q < q2Steps.length; q++) {
        var q2Step = q2Steps[q];
        var q2Part = q2Step.getAttribute('data-q2-part') || '?';
        var q2Checks = q2Step.querySelectorAll('[data-q2-choice]:checked');
        if (q2Checks.length !== 2) {
            return {
                ok: false,
                step: stepNumber(q2Step),
                message: 'For the Madlibs question, part ' + q2Part + ', choose exactly 2 options.'
            };
        }
    }

    var optionVoteSteps = document.querySelectorAll('.option-vote-step');
    for (var k = 0; k < optionVoteSteps.length; k++) {
        var optionStep = optionVoteSteps[k];
        var required = parseInt(optionStep.getAttribute('data-required-selections') || '1', 10);
        var checked = optionStep.querySelectorAll('[data-option-vote-choice]:checked').length;
        if (checked !== required) {
            var name = optionStep.querySelector('h2');
            var roundName = name ? name.textContent.trim() : 'Option Vote';
            return {
                ok: false,
                step: stepNumber(optionStep),
                message: 'For ' + roundName + ', select exactly ' + required + ' option' + (required === 1 ? '' : 's') + '.'
            };
        }
    }

    var legacyStep = document.querySelector('.legacy-q3-step');
    if (legacyStep) {
        var legacyChecks = legacyStep.querySelectorAll('[data-legacy-q3-choice]:checked');
        if (legacyChecks.length !== 2) {
            return {
                ok: false,
                step: stepNumber(legacyStep),
                message: 'For the Option Vote, select exactly 2 options.'
            };
        }
    }

    return { ok: true, step: 1, message: '' };
}

document.addEventListener('DOMContentLoaded', function () {
    var steps = Array.from(document.querySelectorAll('.step'));
    var totalSteps = steps.length;
    var progressFill = document.querySelector('.progress-bar-fill');
    var stepLabel = document.getElementById('step-label');

    if (totalSteps === 0) return;

    function showStep(targetStepNumber) {
        steps.forEach(function (step) { step.classList.remove('current'); });
        var activeStep = document.querySelector('.step[data-step="' + targetStepNumber + '"]');
        if (activeStep) activeStep.classList.add('current');

        if (stepLabel) stepLabel.textContent = targetStepNumber + ' of ' + totalSteps;
        if (progressFill) progressFill.style.width = ((targetStepNumber / totalSteps) * 100) + '%';
    }

    // Q1 point picker, when the saved round structure actually uses Q1.
    var q1Step = document.querySelector('.step[data-question-type="q1"]');
    if (q1Step) {
        var controls = q1Step.querySelectorAll('.points-control');
        for (var i = 0; i < controls.length; i++) {
            (function (control) {
                var catIndex = control.getAttribute('data-cat');
                var hiddenInput = document.getElementById('q1-hidden-' + catIndex);
                var valueSpan = control.querySelector('.points-value');
                var minusBtn = control.querySelector('.points-btn.minus');
                var plusBtn = control.querySelector('.points-btn.plus');

                if (!hiddenInput) return;

                function getCurrent() {
                    var v = parseInt(hiddenInput.value || '0', 10);
                    return isNaN(v) ? 0 : v;
                }

                function setCurrent(v) {
                    hiddenInput.value = String(v);
                    if (valueSpan) valueSpan.textContent = v;
                    updateQ1UI();
                }

                if (minusBtn) {
                    minusBtn.addEventListener('click', function () {
                        var current = getCurrent();
                        if (current > 0) setCurrent(current - 1);
                    });
                }

                if (plusBtn) {
                    plusBtn.addEventListener('click', function () {
                        var current = getCurrent();
                        if (current >= 4 || computeQ1Total() >= 10) return;
                        setCurrent(current + 1);
                    });
                }

                setCurrent(getCurrent());
            })(controls[i]);
        }
        updateQ1UI();
    }

    // Madlibs only exists when a q2_madlib round is present in the builder.
    document.querySelectorAll('.step[data-question-type="q2"]').forEach(function (step) {
        step.querySelectorAll('[data-q2-choice]').forEach(function (box) {
            box.addEventListener('change', function () { updateQ2StepState(step); });
        });
        updateQ2StepState(step);
    });

    // Generic Option Votes.
    document.querySelectorAll('.option-vote-step').forEach(function (step) {
        step.querySelectorAll('[data-option-vote-choice]').forEach(function (box) {
            box.addEventListener('change', function () {
                updateSelectionStep(step, '[data-option-vote-choice]', '[data-option-vote-counter]');
            });
        });
        updateSelectionStep(step, '[data-option-vote-choice]', '[data-option-vote-counter]');
    });

    // Legacy Q3.
    var legacyStep = document.querySelector('.legacy-q3-step');
    if (legacyStep) {
        legacyStep.querySelectorAll('[data-legacy-q3-choice]').forEach(function (box) {
            box.addEventListener('change', function () {
                updateSelectionStep(legacyStep, '[data-legacy-q3-choice]', '[data-legacy-q3-counter]');
            });
        });
        updateSelectionStep(legacyStep, '[data-legacy-q3-choice]', '[data-legacy-q3-counter]');
    }

    document.querySelectorAll('.wizard-next').forEach(function (button) {
        button.addEventListener('click', function () {
            showStep(parseInt(button.getAttribute('data-next-step'), 10));
        });
    });

    document.querySelectorAll('.wizard-back').forEach(function (button) {
        button.addEventListener('click', function () {
            showStep(parseInt(button.getAttribute('data-back-step'), 10));
        });
    });

    var form = document.getElementById('ml_form');
    if (form) {
        form.addEventListener('submit', function (event) {
            var result = validateForm();
            if (!result.ok) {
                event.preventDefault();
                alert(result.message);
                showStep(result.step);
            }
        });
    }

    showStep(stepNumber(steps[0]));
});
