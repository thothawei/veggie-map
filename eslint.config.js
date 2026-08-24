import pluginVue from 'eslint-plugin-vue';
import vueTsEslintConfig from '@vue/eslint-config-typescript';

export default [
    {
        name: 'app/files-to-lint',
        files: ['resources/js/**/*.{ts,mts,tsx,vue}'],
    },
    {
        name: 'app/files-to-ignore',
        ignores: ['public/**', 'node_modules/**', 'vendor/**'],
    },
    ...pluginVue.configs['flat/essential'],
    ...vueTsEslintConfig(),
];
