/**
 * Network Quality - Detecção silenciosa de velocidade
 * Apenas detecta e informa qualidade, sem nenhuma UI
 */

class NetworkQualityManager {
    constructor() {
        this.effectiveType = 'unknown';
        this.downlink = 0;
        this.rtt = 0;
        this.saveData = false;
        this.quality = 'high';
        this.listeners = [];
        this.init();
    }
    
    init() {
        if ('connection' in navigator) {
            const conn = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
            if (conn) {
                this.updateFromAPI(conn);
                conn.addEventListener('change', () => {
                    this.updateFromAPI(conn);
                    this.notifyListeners();
                });
            }
        }
        this.measureSpeed();
        setInterval(() => this.measureSpeed(), 60000);
    }
    
    updateFromAPI(conn) {
        this.effectiveType = conn.effectiveType || 'unknown';
        this.downlink = conn.downlink || 0;
        this.rtt = conn.rtt || 0;
        this.saveData = conn.saveData || false;
        this.quality = this.determineQuality();
    }
    
    async measureSpeed() {
        try {
            const start = Date.now();
            const img = new Image();
            await new Promise((resolve, reject) => {
                img.onload = resolve;
                img.onerror = reject;
                img.src = 'assets/images/default-avatar.svg?t=' + Date.now();
            });
            const ms = Date.now() - start;
            const mbps = (50000 * 8) / (ms / 1000) / 1024 / 1024;
            if (!this.downlink) this.downlink = mbps;
            this.quality = this.determineQuality();
            this.notifyListeners();
        } catch (e) { /* silencioso */ }
    }
    
    determineQuality() {
        if (this.saveData) return 'low';
        
        switch (this.effectiveType) {
            case 'slow-2g':
            case '2g': return 'low';
            case '3g': return 'medium';
            case '4g': return 'high';
        }
        
        if (this.downlink > 0) {
            if (this.downlink < 0.5) return 'low';
            if (this.downlink < 2) return 'medium';
            return 'high';
        }
        
        if (this.rtt > 500) return 'low';
        if (this.rtt > 200) return 'medium';
        
        return 'high';
    }
    
    // low=metadata only, high=auto preload
    getPreload() {
        return this.quality === 'low' ? 'metadata' : 'auto';
    }
    
    onChange(cb) { this.listeners.push(cb); }
    
    notifyListeners() {
        this.listeners.forEach(cb => { try { cb(this.quality); } catch(e) {} });
    }
}

window.networkQuality = new NetworkQualityManager();
