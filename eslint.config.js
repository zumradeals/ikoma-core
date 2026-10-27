import js from '@eslint/js';
import ts from 'typescript-eslint';
import vue from 'eslint-plugin-vue';
import globals from 'globals';
export default ts.config(
  { ignores: ['vendor/**', 'node_modules/**', 'public/build/**', 'storage/**', 'bootstrap/cache/**', 'docs/**', 'playwright-report/**', 'test-results/**'] },
  js.configs.recommended, ...ts.configs.recommended, ...vue.configs['flat/recommended'],
  { files: ['**/*.vue'], languageOptions: { parserOptions: { parser: ts.parser } } },
  { languageOptions: { globals: { ...globals.browser, ...globals.node } }, rules: { 'vue/multi-word-component-names': 'off', 'vue/max-attributes-per-line': 'off', 'vue/html-self-closing': 'off', 'vue/singleline-html-element-content-newline': 'off', 'vue/html-indent': 'off', 'vue/html-closing-bracket-newline': 'off' } },
);
