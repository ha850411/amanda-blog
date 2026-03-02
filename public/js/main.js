const headerApp = Vue.createApp({
    data() {
        return {
            isMenuOpen: false
        }
    },
    methods: {
        toggleMenu() {
            this.isMenuOpen = !this.isMenuOpen;
        }
    }
});

// 確保有 header-app 的元素再進行 mount，避免錯誤
if (document.getElementById('header-app')) {
    headerApp.mount('#header-app');
}
