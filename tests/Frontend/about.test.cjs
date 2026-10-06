const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

const root = path.resolve(__dirname, '../..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');
const vueSource = read('public/js/vue/vue.global.min.js');
const aboutTemplate = read('resources/views/layouts/about.blade.php')
    .match(/<div class="about-description[\s\S]*?<\/div>/)[0];

function renderAbout(page, description) {
    const context = vm.createContext({ baseMixin: {} });
    vm.runInContext(vueSource, context);
    const Vue = context.Vue;
    const createApp = Vue.createApp;
    let rendered;
    Vue.createApp = options => {
        const app = createApp(options);
        // Run the page's real compiler configuration, then compile its sidebar
        // using the bundled Vue runtime without needing a browser DOM.
        app.mount = () => {
            const template = '<div v-pre>' + aboutTemplate.replace(/\{!![\s\S]*?!!\}/, () => description) + '</div>';
            rendered = Vue.compile(template, app.config.compilerOptions)({}, []);
        };
        return app;
    };
    const script = read(`resources/views/${page}.blade.php`)
        .split("@section('scripts')")[1]
        .match(/<script>([\s\S]*?)<\/script>/)[1]
        .replace(/@json\([^)]*\)/g, 'null')
        .replace(/\{\{[\s\S]*?\}\}/g, '0');
    vm.runInContext(script, context);
    return rendered.children[0];
}

for (const page of ['index', 'article']) {
    test(`${page} preserves stored line breaks, blank lines and literal Vue syntax`, () => {
        const description = '嗨，我是 Amanda ☁️\n喜歡記錄生活\n\n📍 台中美食\n{{ literal }}';
        assert.equal(renderAbout(page, description).children, description);
    });

    test(`${page} retains rich text paragraphs, emphasis, lists and links`, () => {
        const description = '<p style="text-align:center"><strong>Amanda</strong></p><ul><li><a href="/contact">聯絡我</a></li></ul>';
        const content = renderAbout(page, description).children;
        assert.equal(content[0].type, 'p');
        assert.equal(content[0].props.style['text-align'], 'center');
        assert.equal(content[0].children[0].type, 'strong');
        assert.equal(content[1].type, 'ul');
        assert.equal(content[1].children[0].type, 'li');
        assert.equal(content[1].children[0].children[0].props.href, '/contact');
    });
}
