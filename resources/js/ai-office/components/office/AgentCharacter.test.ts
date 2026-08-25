import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import AgentCharacter from './AgentCharacter.vue';

function mountCharacter(status = 'idle', role = 'backend') {
    return mount(AgentCharacter, { props: { name: '後端小周', role, status: status as 'idle' } });
}

describe('AgentCharacter', () => {
    it('狀態放在 data-status 上，動畫與濾鏡都由 CSS 依它決定', () => {
        expect(mountCharacter('working').attributes('data-status')).toBe('working');
        expect(mountCharacter('offline').attributes('data-status')).toBe('offline');
    });

    it('等待核准時右手舉起來（位置與長度都變）', () => {
        const raised = mountCharacter('waiting_review').find('.arm.right');
        const normal = mountCharacter('working').find('.arm.right');

        expect(raised.attributes('y')).toBe('4');
        expect(raised.attributes('height')).toBe('6');
        expect(normal.attributes('y')).toBe('9');
    });

    it('用 aria-label 說明是誰、什麼角色、什麼狀態——不是只有顏色', () => {
        expect(mountCharacter('error').attributes('aria-label')).toBe('後端小周（backend）：錯誤');
    });

    it('同一個角色永遠同一個顏色，未知角色用中性灰', () => {
        const backendA = mountCharacter('idle', 'backend').find('.arm.left').attributes('fill');
        const backendB = mountCharacter('working', 'backend').find('.arm.left').attributes('fill');

        expect(backendA).toBe(backendB);
        expect(mountCharacter('idle', 'marketing').find('.arm.left').attributes('fill')).toBe('#8a97a6');
    });

    it('是 SVG 方塊拼的，而且關掉抗鋸齒（點陣圖是規格禁止的）', () => {
        const wrapper = mountCharacter();

        expect(wrapper.element.tagName.toLowerCase()).toBe('svg');
        expect(wrapper.attributes('shape-rendering')).toBe('crispEdges');
        expect(wrapper.find('image').exists()).toBe(false);
    });
});
