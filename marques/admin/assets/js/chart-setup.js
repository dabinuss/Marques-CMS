document.addEventListener('DOMContentLoaded', function() {
    // Prüfen, ob Chart.js geladen ist
    if (typeof Chart === 'undefined') {
        console.error('Chart.js ist nicht geladen!');
        return;
    }
    
    // Prüfen, ob die Daten verfügbar sind
    if (typeof window.siteStats === 'undefined') {
        console.error('siteStats-Daten sind nicht verfügbar!');
        return;
    }
    
    // Diagrammdaten für die letzten 14 Tage vorbereiten
    var days = 14;
    var dailyData = [];
    var labels = [];
    var dailyStats = window.siteStats.daily_stats || {};

    // Für jeden der letzten 14 Tage:
    for (var i = days - 1; i >= 0; i--) {
        var currentDate = new Date();
        currentDate.setDate(currentDate.getDate() - i);
        
        // Datum im Format "YYYY-MM-DD" erstellen (als Schlüssel)
        var year = currentDate.getFullYear();
        var month = (currentDate.getMonth() + 1).toString().padStart(2, '0');
        var day = currentDate.getDate().toString().padStart(2, '0');
        var dateKey = year + '-' + month + '-' + day;
        
        // Label im Format "dd.mm" hinzufügen
        labels.push(day + '.' + month);
        
        // Wert aus daily_stats (oder 0, wenn nicht vorhanden)
        dailyData.push(dailyStats[dateKey] || 0);
    }
    
    // Besuchsdiagramm (letzte 14 Tage)
    var visitCtx = document.getElementById('visitsChart');
    if (visitCtx && dailyData.length > 0) {
        visitCtx = visitCtx.getContext('2d');
        new Chart(visitCtx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Besuche',
                    data: dailyData,
                    borderColor: '#4a6fa5',
                    backgroundColor: 'rgba(74, 111, 165, 0.1)',
                    fill: true,
                    tension: 0.4,
                    borderWidth: 2,
                    pointRadius: 3,
                    pointBackgroundColor: '#4a6fa5'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        mode: 'index',
                        intersect: false,
                        callbacks: {
                            label: function(context) {
                                return 'Besuche: ' + context.parsed.y;
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { precision: 0 }
                    }
                }
            }
        });
    } else if (visitCtx) {
        // Falls Canvas existiert, aber keine Daten vorhanden sind
        var noDataMsg = document.createElement('p');
        noDataMsg.className = 'admin-no-data';
        noDataMsg.textContent = 'Keine Besuchsdaten für die Diagrammdarstellung verfügbar.';
        visitCtx.parentNode.replaceChild(noDataMsg, visitCtx);
    }
    
    // Geräte-Diagramm (z.B. mobile, desktop, tablet)
    var deviceStats = window.siteStats.device_stats || {};
    var deviceCtx = document.getElementById('deviceChart');
    if (deviceCtx && Object.keys(deviceStats).length > 0) {
        deviceCtx = deviceCtx.getContext('2d');
        new Chart(deviceCtx, {
            type: 'doughnut',
            data: {
                labels: Object.keys(deviceStats),
                datasets: [{
                    data: Object.values(deviceStats),
                    backgroundColor: ['#4a6fa5', '#6c757d', '#28a745'],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom' }
                },
                cutout: '60%'
            }
        });
    }
});
