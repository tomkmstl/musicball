document.addEventListener('DOMContentLoaded', function () {
    var canvas = document.getElementById('submissionPie');
    if (!canvas) return;

    var total = parseInt(canvas.dataset.total, 10) || 0;
    var submitted = parseInt(canvas.dataset.submitted, 10) || 0;
    if (total <= 0) return;

    var remaining = Math.max(total - submitted, 0);
    var ctx = canvas.getContext('2d');

    // Optional: hide global legend
    if (window.Chart && Chart.defaults && Chart.defaults.plugins && Chart.defaults.plugins.legend) {
        Chart.defaults.plugins.legend.display = false;
    }

    new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: ['Submitted', 'Pending'],
			datasets: [{
				data: [submitted, remaining],
				backgroundColor: [
					'#a855f7', // submitted (green)
					'#38bdf8'  // pending (light gray)
				],
				borderWidth: 1
			}]
        },
        options: {
            cutout: '60%',
            plugins: {
                tooltip: {
                    callbacks: {
                        label: function (context) {
                            var label = context.label || '';
                            var value = context.raw || 0;
                            return label + ': ' + value;
                        }
                    }
                }
            }
        }
    });
});
