<template>

  <div class="landing-page" :class="{ 'dark-theme': isDarkTheme }" @wheel="handleWheel" @scroll="handleScroll" ref="landingPageRef">





    <!-- 背景装饰 -->

    <div class="background-decoration">

      <div class="bg-circle circle-1" :class="{ 'dark-mode': isDarkTheme }"></div>

      <div class="bg-circle circle-2" :class="{ 'dark-mode': isDarkTheme }"></div>

      <div class="bg-circle circle-3" :class="{ 'dark-mode': isDarkTheme }"></div>

    </div>



    <!-- 顶部工具栏 -->

    <div class="top-toolbar">

      <ThemeToggle />

      <LanguageSelector />

    </div>



    <!-- 中央内容区 -->

    <div class="content-container">

      <div class="site-title">

        <img v-if="siteConfig.showLogo" src="/images/logo.png" alt="Logo" class="site-logo-img" />

        {{ siteConfig.siteName }}

      </div>

      <div class="landing-text">{{ $t('landing.mainText') }}</div>

    </div>



    <!-- 底部箭头 -->

    <div class="scroll-arrow-container" @click="navigateToLogin">

      <div class="scroll-arrow">

        <IconChevronDown :size="32" :stroke-width="1.5" />

      </div>

      <div class="scroll-text">{{ $t('landing.scrollText') }}</div>

    </div>



    <!-- 页面过渡遮罩 -->

    <div class="page-transition-mask" :class="{ 'active': isTransitioning }"></div>

  </div>

</template>



<script>

import { ref, onMounted, onUnmounted, computed } from 'vue';

import { useRouter } from 'vue-router';

import { useStore } from 'vuex';

import { useI18n } from 'vue-i18n';

import { SITE_CONFIG, DEFAULT_CONFIG } from '@/utils/baseConfig';


import ThemeToggle from '@/components/common/ThemeToggle.vue';

import LanguageSelector from '@/components/common/LanguageSelector.vue';

import { IconChevronDown } from '@tabler/icons-vue';

import DomainAuthAlert from '@/components/common/DomainAuthAlert.vue';



export default {

  name: 'LandingPage',

  components: {

    ThemeToggle,

    LanguageSelector,

    IconChevronDown,

    DomainAuthAlert

  },

  setup() {

    const router = useRouter();

    const store = useStore();

    const { t } = useI18n();

    const landingPageRef = ref(null);



    const isDarkTheme = computed(() => store.getters.currentTheme === 'dark');



    const siteConfig = ref(SITE_CONFIG);

    const defaultConfig = ref(DEFAULT_CONFIG);



    const isTransitioning = ref(false);






    const handleScroll = (e) => {

      if (e.currentTarget === landingPageRef.value && window.scrollY > 100) {

        navigateToLogin();

      }

    };



    const handleWheel = (e) => {

      if (e.currentTarget === landingPageRef.value && e.deltaY > 0) {

        navigateToLogin();

      }

    };



    const navigateToLogin = () => {

      if (isTransitioning.value) {

        return;

      }



      isTransitioning.value = true;



      document.body.classList.add('page-transitioning');



      console.log(t('landing.navigatingToLogin', 'Navigating to login page'));



      setTimeout(() => {

        router.push('/login');

      }, 600);
    };



    let touchStartY = 0;

    let handleTouchStart, handleTouchMove;



    onMounted(() => {





      handleTouchStart = (e) => {

        if (e.currentTarget === landingPageRef.value || landingPageRef.value.contains(e.target)) {

          touchStartY = e.touches[0].clientY;

        }

      };



      handleTouchMove = (e) => {

        if (e.currentTarget === landingPageRef.value || landingPageRef.value.contains(e.target)) {

          const touchY = e.touches[0].clientY;

          if (touchStartY - touchY > 50) {
            navigateToLogin();

          }

        }

      };



      if (landingPageRef.value) {

        landingPageRef.value.addEventListener('touchstart', handleTouchStart, { passive: true });

        landingPageRef.value.addEventListener('touchmove', handleTouchMove, { passive: true });

      }

    });



    onUnmounted(() => {

      if (landingPageRef.value) {

        landingPageRef.value.removeEventListener('touchstart', handleTouchStart);

        landingPageRef.value.removeEventListener('touchmove', handleTouchMove);

      }

    });



    return {

      landingPageRef,

      siteConfig,

      defaultConfig,

      isDarkTheme,

      isTransitioning,

      navigateToLogin,

      handleScroll,

      handleWheel,


    };

  }

};

</script>



<style lang="scss" scoped>

.landing-page {

  position: relative;

  width: 100%;

  height: 100vh;

  overflow: hidden;

  display: flex;

  flex-direction: column;

  justify-content: center;

  align-items: center;

  background-color: var(--background-color);

  color: var(--text-color);

  transition: background-color 0.3s ease, color 0.3s ease;

}





.background-decoration {

  position: absolute;

  top: 0;

  left: 0;

  width: 100%;

  height: 100%;

  z-index: 0;

  overflow: hidden;



  @supports (-webkit-touch-callout: none) {

    display: none;

  }



  .bg-circle {

    position: absolute;

    border-radius: 50%;

    filter: blur(80px);

    opacity: 0.4;

    animation: float 20s infinite ease-in-out;

    transition: opacity 0.5s ease, background-color 0.5s ease;



    @supports (-webkit-touch-callout: none) {

      filter: blur(20px);

      opacity: 0.15;

      animation-duration: 40s;
    }



    &.dark-mode {

      opacity: 0.25;

      filter: blur(100px) saturate(0.7);



      @supports (-webkit-touch-callout: none) {

        filter: blur(15px) saturate(0.5);

        opacity: 0.1;

      }

    }

  }



  .circle-1 {

    width: 600px;

    height: 600px;

    background: var(--theme-color);

    top: -10%;

    left: -10%;

    animation-duration: 25s;



    &.dark-mode {

      background: rgba(0, 148, 124, 0.6);

    }

  }



  .circle-2 {

    width: 500px;

    height: 500px;

    background: #A747FE;

    top: 40%;

    right: -5%;

    animation-duration: 30s;



    &.dark-mode {

      background: rgba(167, 71, 254, 0.5);

    }

  }



  .circle-3 {

    width: 450px;

    height: 450px;

    background: #37DEC9;

    bottom: -10%;

    left: 20%;

    animation-duration: 35s;



    &.dark-mode {

      background: rgba(55, 222, 201, 0.5);

    }

  }

}



@keyframes float {

  0%, 100% {

    transform: translate(0, 0) rotate(0deg);

  }

  25% {

    transform: translate(5%, 5%) rotate(5deg);

  }

  50% {

    transform: translate(0, 10%) rotate(0deg);

  }

  75% {

    transform: translate(-5%, 5%) rotate(-5deg);

  }

}





.top-toolbar {

  position: fixed;

  top: 20px;

  right: 25px;

  display: flex;

  gap: 12px;

  z-index: 100;

}





.content-container {

  position: relative;

  z-index: 10;

  text-align: center;

  padding: 0 20px;

  max-width: 800px;

}



.site-title {

  font-size: 48px;

  font-weight: 700;

  margin-bottom: 20px;

  background: linear-gradient(to right, var(--theme-color), #a78bfa);

  -webkit-background-clip: text;

  background-clip: text;

  color: transparent;

  text-align: center;

  letter-spacing: -0.5px;

  display: flex;

  align-items: center;

  justify-content: center;

  gap: 15px;



  .site-logo-img {

    height: 40px;

    width: 40px;

    border-radius: 10px;

    object-fit: cover;

  }

}



.landing-text {

  font-size: 1.5rem;

  font-weight: 400;

  line-height: 1.5;

  margin-bottom: 2rem;

  color: var(--text-color);

  opacity: 0.9;



  @media (max-width: 768px) {

    font-size: 1.25rem;

  }



  @media (max-width: 480px) {

    font-size: 1rem;

  }

}





.scroll-arrow-container {

  position: fixed;

  bottom: 40px;

  left: 50%;

  transform: translateX(-50%);

  display: flex;

  flex-direction: column;

  align-items: center;

  cursor: pointer;

  z-index: 10;

  transition: transform 0.3s ease;



  &:hover {

    transform: translateX(-50%) translateY(5px);



    .scroll-arrow {

      animation-play-state: paused;

    }

  }

}



.scroll-arrow {

  color: var(--theme-color);

  animation: bounce 2s infinite;

  margin-bottom: 8px;

}



.scroll-text {

  font-size: 0.875rem;

  color: var(--secondary-text-color);

  opacity: 0.8;

}



@keyframes bounce {

  0%, 20%, 50%, 80%, 100% {

    transform: translateY(0);

  }

  40% {

    transform: translateY(-20px);

  }

  60% {

    transform: translateY(-10px);

  }

}





.page-transition-mask {

  position: fixed;

  top: 0;

  left: 0;

  width: 100%;

  height: 100%;

  background-color: var(--background-color);

  z-index: 1000;

  opacity: 0;

  pointer-events: none;

  transition: opacity 0.6s cubic-bezier(0.4, 0, 0.2, 1);



  &.active {

    opacity: 1;

    pointer-events: all;

  }

}





@media (max-width: 768px) {

  .scroll-arrow-container {

    bottom: 30px;

  }

}

</style>
