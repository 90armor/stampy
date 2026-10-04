// Renders the Attendance Trend bar chart on the dashboard. Kept as its own
// Vite entry point (see vite.config.js) so Chart.js is only loaded on the
// page that needs it, and only registers the chart types it actually uses.
//
// One bar per day: the attendance rate (present + incomplete, Phase 2.7). A non-working day
// (every scoped row Off or Holiday) has a null value and a marker instead,
// drawn as a muted "Off"/"Holiday" label on the baseline — never a 0% bar,
// which would read as "nobody came in". Bars use primary-500, the same green
// as the department attendance bars, so the page has one data-viz green.
//
// Today is pending until it is calculated: it carries a muted "Today" marker,
// and the checked-in share so far is drawn as a lighter, provisional bar with
// the marker above it — never as a final 0% (docs/ATTENDANCE_UI.md).
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

        const bars = chart.getDatasetMeta(0).data;

        markers.forEach((marker, index) => {
            if (!marker) {
                return;
            }

            const value = chart.data.datasets[0].data[index];
            const y = value === null || value === undefined
                ? chartArea.bottom - 6
                : Math.min(chartArea.bottom - 6, bars[index].y - 4);
            const x = scales.x.getPixelForValue(index);

            // A marker wider than its slot ("Not calculated" at phone width)
            // wraps onto two lines, bottom-aligned, rather than spilling into
            // its neighbours.
            const slot = chartArea.width / Math.max(1, markers.length) - 4;
            const words = marker.split(' ');
            if (ctx.measureText(marker).width > slot && words.length > 1) {
                ctx.fillText(words.slice(1).join(' '), x, y);
                ctx.fillText(words[0], x, y - 14);
            } else {
                ctx.fillText(marker, x, y);
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
    // Neutrals follow the theme's slate scale (stone in light mode, zinc in
    // dark — resources/css/app.css), read from the same CSS variables the
    // classes use, so the chart never keeps a hard-coded warm grey.
    const slateVars = getComputedStyle(document.documentElement);
    const slate = (step, alpha = 1) => `rgb(${slateVars.getPropertyValue(`--slate-${step}`).trim()} / ${alpha})`;
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const markers = JSON.parse(canvas.dataset.markers || '[]');
    const pending = JSON.parse(canvas.dataset.pending || '[]');
    const barColor = (index) => (pending[index] ? (dark ? '#1e4232' : '#b9d9c8') : '#3f8266');
    const muted = dark ? slate(400) : slate(500);

    new Chart(canvas, {
        type: 'bar',
        data: {
            labels: JSON.parse(canvas.dataset.labels),
            datasets: [{
                data: JSON.parse(canvas.dataset.values),
                backgroundColor: (ctx) => barColor(ctx.dataIndex),
                hoverBackgroundColor: (ctx) => (pending[ctx.dataIndex] ? barColor(ctx.dataIndex) : '#2f6850'),
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
                    backgroundColor: dark ? slate(100) : slate(900),
                    titleColor: dark ? slate(900) : slate(100),
                    bodyColor: dark ? slate(600) : slate(300),
                    padding: 10,
                    displayColors: false,
                    filter: (item) => item.raw !== null,
                    callbacks: {
                        label: (ctx) => (pending[ctx.dataIndex] ? `${ctx.parsed.y}% checked in so far` : `${ctx.parsed.y}% attended`),
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
                    grid: { color: `rgb(${slateVars.getPropertyValue('--slate-divider').trim()})` }, // the divider line token
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
