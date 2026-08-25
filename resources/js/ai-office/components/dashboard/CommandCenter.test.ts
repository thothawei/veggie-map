import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import CommandCenter from './CommandCenter.vue';
import type { AiOfficeProject } from '../../types';

function project(id: number, status: AiOfficeProject['status'] = 'active'): AiOfficeProject {
    return {
        id,
        name: `專案 ${id}`,
        description: null,
        repository_url: null,
        workspace_path: null,
        status,
        created_by: 1,
        task_count: 4,
        created_at: null,
        updated_at: null,
    };
}

describe('CommandCenter', () => {
    it('沒有寫入權限就不顯示建立表單', () => {
        const wrapper = mount(CommandCenter, { props: { projects: [project(1)], canCreate: false } });

        expect(wrapper.find('.new-project').exists()).toBe(false);
    });

    it('建立時把去掉空白的名稱與描述送出去', async () => {
        const wrapper = mount(CommandCenter, { props: { projects: [], canCreate: true } });
        const inputs = wrapper.findAll('input');

        await inputs[0].setValue('  待辦 API  ');
        await inputs[1].setValue(' 做一個 Todo API ');
        await wrapper.find('form').trigger('submit');

        expect(wrapper.emitted('create')).toEqual([[{ name: '待辦 API', description: '做一個 Todo API' }]]);
    });

    it('名稱空白時擋在前端並顯示錯誤，不送出請求', async () => {
        const wrapper = mount(CommandCenter, { props: { projects: [], canCreate: true } });

        await wrapper.find('form').trigger('submit');

        expect(wrapper.emitted('create')).toBeUndefined();
        expect(wrapper.find('.error').text()).toBe('專案名稱必填');
    });

    it('描述留空時送 null 而不是空字串', async () => {
        const wrapper = mount(CommandCenter, { props: { projects: [], canCreate: true } });

        await wrapper.findAll('input')[0].setValue('只有名字');
        await wrapper.find('form').trigger('submit');

        expect(wrapper.emitted('create')).toEqual([[{ name: '只有名字', description: null }]]);
    });

    it('狀態顯示中文，並把原始狀態碼留在 data-status 上', () => {
        const wrapper = mount(CommandCenter, { props: { projects: [project(1, 'planning')] } });

        expect(wrapper.find('.status').text()).toBe('規劃中');
        expect(wrapper.find('.status').attributes('data-status')).toBe('planning');
    });

    it('點專案把 id 傳出去', async () => {
        const wrapper = mount(CommandCenter, { props: { projects: [project(11)] } });

        await wrapper.find('.project').trigger('click');

        expect(wrapper.emitted('open')).toEqual([[11]]);
    });

    it('沒有專案時給提示', () => {
        const wrapper = mount(CommandCenter, { props: { projects: [] } });

        expect(wrapper.find('.hint').text()).toContain('還沒有專案');
    });
});
