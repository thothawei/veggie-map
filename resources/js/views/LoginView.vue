<script setup lang="ts">
import { ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useAuthStore } from '@/stores/auth';
import { extractApiErrorMessage } from '@/lib/apiError';

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();

const email = ref('');
const password = ref('');
const loading = ref(false);
const error = ref<string | null>(null);

async function submit() {
    loading.value = true;
    error.value = null;
    try {
        await auth.login(email.value, password.value);
        router.push((route.query.redirect as string) ?? '/');
    } catch (e: unknown) {
        error.value = extractApiErrorMessage(e, '登入失敗');
    } finally {
        loading.value = false;
    }
}
</script>

<template>
    <div class="auth-form">
        <h1>登入</h1>
        <form @submit.prevent="submit">
            <label>
                Email
                <input v-model="email" type="email" required autocomplete="email" />
            </label>
            <label>
                密碼
                <input v-model="password" type="password" required autocomplete="current-password" />
            </label>
            <p v-if="error" class="error">{{ error }}</p>
            <button type="submit" :disabled="loading">{{ loading ? '登入中…' : '登入' }}</button>
        </form>
        <p>還沒有帳號？<RouterLink to="/register">註冊</RouterLink></p>
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

.error {
    color: #c53030;
}
</style>
