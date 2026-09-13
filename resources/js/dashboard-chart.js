// Renders the Attendance Trend line chart on the dashboard. Kept as its own
// Vite entry point (see vite.config.js) so Chart.js is only loaded on the
// page that needs it, and only registers the chart types it actually uses.
import {
    Chart,
    LineController,
    LineElement,
    PointElement,
    LinearScale,
    CategoryScale,
    Tooltip,
    Filler,
} from 'chart.js';

Chart.register(LineController, LineElement, PointElement, LinearScale, CategoryScale, Tooltip, Filler);

function initAttendanceTrendChart() {
    const canvas = document.getElementById('attendance-trend-chart');
    if (!canvas) {
        return;
    }

    Chart.getChart(canvas)?.destroy();

    new Chart(canvas, {
        type: 'line',
        data: {
            labels: JSON.parse(canvas.dataset.labels),
            datasets: [{
                data: JSON.parse(canvas.dataset.values),
                borderColor: '#26b57e',
                backgroundColor: 'rgba(38, 181, 126, 0.12)',
                borderWidth: 2,
                pointRadius: 3,
                pointBackgroundColor: '#26b57e',
                pointBorderColor: '#ffffff',
                pointBorderWidth: 2,
                tension: 0.35,
                fill: true,
            }],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: '#1c1917',
                    titleColor: '#f5f5f4',
                    bodyColor: '#d6d3d1',
                    padding: 10,
                    displayColors: false,
                    callbacks: {
                        label: (ctx) => `${ctx.parsed.y}% attendance`,
                    },
                },
            },
            scales: {
                x: {
                    grid: { display: false },
                    ticks: { color: '#78716c', font: { size: 12 } },
                },
                y: {
                    min: 0,
                    max: 100,
                    grid: { color: 'rgba(120, 113, 108, 0.12)' },
                    ticks: {
                        color: '#78716c',
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
