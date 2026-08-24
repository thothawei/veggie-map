<script setup lang="ts">
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import { useAuthStore } from '@/stores/auth';

const router = useRouter();
const auth = useAuthStore();

const name = ref('');
const email = ref('');
const password = ref('');
const passwordConfirmation = ref('');
const loading = ref(false);
const errors = ref<Record<string, string[]>>({});

async function submit() {
    loading.value = true;
    errors.value = {};
    try {
        await auth.register(name.value, email.value, password.value, passwordConfirmation.value);
        router.push('/');
    } catch (e: any) {
        errors.value = e?.response?.data?.error?.fields ?? { _: [e?.response?.data?.error?.message ?? '註冊失敗'] };
    } finally {
        loading.value = false;
    }
}
</script>

<template>
    <div class="auth-form">
        <h1>註冊</h1>
        <form @submit.prevent="submit">
            <label>
                姓名
                <input v-model="name" type="text" required autocomplete="name" />
            </label>
            <label>
                Email
                <input v-model="email" type="email" required autocomplete="email" />
            </label>
            <label>
                密碼（至少 8 碼）
                <input v-model="password" type="password" required autocomplete="new-password" />
            </label>
            <label>
                確認密碼
                <input v-model="passwordConfirmation" type="password" required autocomplete="new-password" />
            </label>
            <ul v-if="Object.keys(errors).length" class="errors">
                <li v-for="(messages, field) in errors" :key="field">{{ messages.join(' ') }}</li>
            </ul>
            <button type="submit" :disabled="loading">{{ loading ? '註冊中…' : '註冊' }}</button>
        </form>
        <p>已經有帳號？<RouterLink to="/login">登入</RouterLink></p>
    </div>
</template>

<style scoped>
.auth-form {
    max-width: 360px;
    margin: 3rem auto;
    padding: 0 1rem;
}

form {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

label {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
    font-size: 0.9rem;
}

input {
    padding: 0.5rem;
    border: 1px solid #cbd5e0;
    border-radius: 6px;
}

button {
    padding: 0.6rem;
    background: #2f855a;
    color: #fff;
    border: none;
    border-radius: 6px;
    cursor: pointer;
}

.errors {
    color: #c53030;
    font-size: 0.85rem;
    padding-left: 1.2rem;
}
</style>
