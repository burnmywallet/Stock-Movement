/**
 * ============================================================================
 * Logistox - Frontend Router (SPA)
 * ============================================================================
 */

const Router = (() => {
    const routes = {};
    let currentPage = null;

    const register = (path, handler) => {
        routes[path] = handler;
    };

    const navigate = (path) => {
        window.history.pushState({}, '', `/inventory-system/frontend/${path}`);
        handleRoute();
    };

    const handleRoute = () => {
        const path = window.location.pathname.replace('/inventory-system/frontend/', '') || 'index.html';
        const route = routes[path];
        
        if (route) {
            currentPage = path;
            route();
        } else {
            // 404
            console.warn('Route not found:', path);
        }
    };

    const init = () => {
        window.addEventListener('popstate', handleRoute);
        handleRoute();
    };

    return { register, navigate, init, getCurrentPage: () => currentPage };
})();

window.Router = Router;