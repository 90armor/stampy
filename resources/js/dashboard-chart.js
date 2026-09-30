// Renders the Attendance Trend bar chart on the dashboard. Kept as its own
// Vite entry point (see vite.config.js) so Chart.js is only loaded on the
// page that needs it, and only registers the chart types it actually uses.
//
// One bar per day: the present share of active employees. A non-working day
// (every scoped row Off or Holiday) has a null value and a marker instead,
// drawn as a muted "Off"/"Holiday" label on the baseline — never a 0% bar,
// which would read as "nobody came in". Bars use primary-500, the same green
// as the department attendance bars, so the page has one data-viz green.
import {
    Chart,
    BarController,
    BarElement,
    LinearScale,
    CategoryScale,
    Tooltip,
} from 'chart.js';

Chart.register(BarController, BarElement, LinearScale, CategoryScale, Tooltip);

const markerLabels = {
    id: 'markerLabels',
    afterDatasetsDraw(chart, _args, options) {
        const markers = options.markers || [];
        const { ctx, chartArea, scales } = chart;

        ctx.save();
        ctx.fillStyle = options.color;
        ctx.font = '500 12px Inter, ui-sans-serif, system-ui, sans-serif';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'bottom';

        markers.forEach((marker, index) => {
            if (marker) {
                ctx.fillText(marker, scales.x.getPixelForValue(index), chartArea.bottom - 6);
            }
        });

        ctx.restore();
    },
};

function initAttendanceTrendChart() {
    const canvas = document.getElementById('attendance-trend-chart');
    if (!canvas) {
        return;
    }

    Chart.getChart(canvas)?.destroy();

    const dark = document.documentElement.classList.contains('dark');
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const markers = JSON.parse(canvas.dataset.markers || '[]');
    const muted = dark ? '#a8a29e' : '#78716c';

    new Chart(canvas, {
        type: 'bar',
        data: {
            labels: JSON.parse(canvas.dataset.labels),
            datasets: [{
                data: JSON.parse(canvas.dataset.values),
                backgroundColor: '#3f8266',
                hoverBackgroundColor: '#2f6850',
                borderRadius: 4,
                maxBarThickness: 40,
            }],
        },
        plugins: [markerLabels],
        options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: reducedMotion ? false : undefined,
            plugins: {
                legend: { display: false },
                markerLabels: { markers, color: muted },
                tooltip: {
                    backgroundColor: dark ? '#f5f5f4' : '#1c1917',
                    titleColor: dark ? '#1c1917' : '#f5f5f4',
                    bodyColor: dark ? '#57534e' : '#d6d3d1',
                    padding: 10,
                    displayColors: false,
                    filter: (item) => item.raw !== null,
                    callbacks: {
                        label: (ctx) => `${ctx.parsed.y}% present`,
                    },
                },
            },
            scales: {
                x: {
                    grid: { display: false },
                    ticks: { color: muted, font: { size: 12 } },
                },
                y: {
                    min: 0,
                    max: 100,
                    grid: { color: dark ? 'rgba(168, 162, 158, 0.14)' : 'rgba(120, 113, 108, 0.12)' },
                    ticks: {
                        color: muted,
                        font: { size: 12 },
                        stepSize: 25,
                        callback: (value) => `${value}%`,
                    },
                },
            },
        },
    });
}

document.addEventListener('DOMContentLoaded', initAttendanceTrendChart);
document.addEventListener('livewire:navigated', initAttendanceTrendChart);

new MutationObserver((mutations) => {
    if (mutations.some((mutation) => mutation.attributeName === 'class')) {
        initAttendanceTrendChart();
    }
}).observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
