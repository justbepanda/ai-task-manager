const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const base = process.env.BASE_URL || 'http://web';
const outDir = process.env.OUT_DIR || '/out';
const email = process.env.ADMIN_EMAIL || 'developer@kelnik.ru';
const password = process.env.ADMIN_PASSWORD || 'developer';
const width = Number(process.env.VIEWPORT_WIDTH || 1600);
const height = Number(process.env.VIEWPORT_HEIGHT || 1000);

function loadIds() {
  const candidates = [
    path.join(outDir, 'ids.json'),
    '/ids.json',
    path.join(__dirname, 'ids.json'),
  ];
  for (const p of candidates) {
    try {
      if (fs.existsSync(p)) {
        return JSON.parse(fs.readFileSync(p, 'utf8'));
      }
    } catch (_) {}
  }
  return {};
}

const ids = loadIds();
const messageId = ids.message_id || process.env.MESSAGE_ID;
const taskId = ids.task_id || process.env.TASK_ID;
const projectId = ids.project_id || process.env.PROJECT_ID;
const actionId = ids.manager_action_id || process.env.MANAGER_ACTION_ID;
const eventId = ids.event_id || process.env.EVENT_ID;
const executorId = ids.executor_id || process.env.EXECUTOR_ID;

/**
 * mode:
 *   single — один скрин первого экрана (списки: таблица просто продолжается вниз)
 *   blocks — несколько скринов, если ниже есть другие смысловые блоки (не «ещё строки таблицы»)
 */
const PAGES = [
  {
    path: '/admin/main',
    prefix: '01-dashboard',
    mode: 'blocks',
    title: 'Обзор',
    intro:
      'Сводные плашки, очередь действий менеджера и лента событий.',
  },
  {
    path: '/admin/messages',
    prefix: '02-inbox',
    mode: 'single',
    title: 'Входящие',
    intro:
      'Плашки-фильтры, фильтры и список писем с кратким описанием от ИИ.',
  },
  {
    path: '/admin/projects',
    prefix: '03-projects',
    mode: 'single',
    title: 'Проекты',
    intro: 'Список проектов менеджера.',
  },
  {
    path: '/admin/tasks',
    prefix: '04-tasks',
    mode: 'single',
    title: 'Задачи',
    intro: 'Список задач (Redmine / Jira / Aspro / переписка) с менеджером задачи.',
  },
  {
    path: '/admin/manager-actions',
    prefix: '05-manager-actions',
    mode: 'single',
    title: 'Действия менеджера',
    intro: 'Очередь предложений ИИ: проверить / ответить, подтвердить или отклонить.',
  },
  {
    path: '/admin/events',
    prefix: '06-events',
    mode: 'single',
    title: 'События',
    intro: 'Нормализованные события по письмам («что произошло»).',
  },
  {
    path: '/admin/messages/assign',
    prefix: '07-assign',
    mode: 'single',
    title: 'Распределение по проектам',
    intro: 'Ручная массовая привязка нераспределённых писем к проектам (по правилам, без LLM).',
  },
  {
    path: '/admin/messages/assign-llm',
    prefix: '08-assign-llm',
    mode: 'single',
    title: 'Распределение (LLM)',
    intro: 'Подсказки ИИ по привязке писем к проектам.',
  },
  {
    path: '/admin/settings',
    prefix: '09-settings',
    mode: 'blocks',
    title: 'Настройки',
    intro:
      'Автосоздание проектов из Redmine и автораспределение через LLM, пороги уверенности.',
  },
  {
    path: '/admin/mail-filters',
    prefix: '10-mail-filters',
    mode: 'blocks',
    title: 'Фильтры почты',
    intro: 'Глобальный чёрный список: адреса, домены, стоп-слова.',
  },
  {
    path: '/admin/client-companies',
    prefix: '11-clients',
    mode: 'single',
    title: 'Клиенты',
    intro: 'Справочник компаний-клиентов.',
  },
  {
    path: '/admin/executors',
    prefix: '12-executors',
    mode: 'single',
    title: 'Исполнители',
    intro: 'Сотрудники нашей команды (назначаются на задачи).',
  },
  {
    path: '/admin/metrics/hypothesis',
    prefix: '13-metrics',
    mode: 'blocks',
    title: 'Метрики гипотезы',
    intro: 'Показатели для оценки гипотезы MVP (письма, проекты, черновики, исправления).',
  },
  {
    path: '/admin/admin-tools',
    prefix: '14-admin-tools',
    mode: 'blocks',
    title: 'Сервисные команды',
    intro: 'Кнопки служебных команд (синхронизация, переобработка, backfill и т.д.).',
  },
  {
    path: '/admin/messages/ignored',
    prefix: '15-ignored',
    mode: 'single',
    title: 'Игнорируемые',
    intro: 'Письма из глобального чёрного списка.',
  },
];

// Detail / inner pages (need real IDs from ids.json)
if (messageId) {
  PAGES.push({
    path: `/admin/messages/${messageId}`,
    prefix: '16-message-view',
    mode: 'blocks',
    title: 'Карточка письма',
    intro:
      'Одно письмо: метаданные, классификация, краткое описание ИИ, текст, журнал обработки, правки. Ниже — отдельные блоки карточки.',
  });
}
if (taskId) {
  PAGES.push({
    path: `/admin/tasks/${taskId}`,
    prefix: '17-task-view',
    mode: 'blocks',
    title: 'Карточка задачи',
    intro:
      'Задача проекта: ключ, статус, менеджер и исполнители задачи (можно менять), связанные письма.',
  });
}
if (projectId) {
  PAGES.push({
    path: `/admin/projects/${projectId}/edit`,
    prefix: '18-project-edit',
    mode: 'blocks',
    title: 'Карточка проекта',
    intro:
      'Проект: основные данные и правила сопоставления, менеджеры/исполнители с задач (только просмотр), задачи, письма, события.',
  });
}
if (actionId) {
  PAGES.push({
    path: `/admin/manager-actions/${actionId}`,
    prefix: '19-manager-action-view',
    mode: 'blocks',
    title: 'Карточка действия менеджера',
    intro:
      'Предложение ИИ: зачем нужно действие, черновик ответа, кнопки подтвердить / править / отклонить.',
  });
}
if (eventId) {
  PAGES.push({
    path: `/admin/events/${eventId}`,
    prefix: '20-event-view',
    mode: 'blocks',
    title: 'Карточка события',
    intro: 'Нормализованное событие по письму: тип, summary, проект, уверенность.',
  });
}
if (executorId) {
  PAGES.push({
    path: `/admin/executors/${executorId}/edit`,
    prefix: '21-executor-edit',
    mode: 'blocks',
    title: 'Карточка исполнителя',
    intro: 'Сотрудник: контакты, активность, связанные проекты.',
  });
}

console.log('Detail IDs:', { messageId, taskId, projectId, actionId, eventId, executorId });

(async () => {
  // Clean previous captures for our prefixes
  for (const p of PAGES) {
    for (const f of fs.readdirSync(outDir)) {
      if (f.startsWith(p.prefix)) {
        fs.unlinkSync(path.join(outDir, f));
      }
    }
  }
  // Also remove legacy names
  for (const legacy of ['01-dashboard.png', '02-inbox.png', '01-dashboard-full.png', '02-inbox-full.png']) {
    const fp = path.join(outDir, legacy);
    if (fs.existsSync(fp)) fs.unlinkSync(fp);
  }

  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width, height } });

  await page.goto(`${base}/admin/login`, { waitUntil: 'domcontentloaded' });
  await page.fill('input[name="email"]', email);
  await page.fill('input[name="password"]', password);
  await page.click('button[type="submit"]');
  await page.waitForURL(
    (url) => url.pathname.includes('/admin') && !url.pathname.endsWith('/login'),
    { timeout: 60000 },
  );

  async function open(urlPath) {
    await page.goto(`${base}${urlPath}`, { waitUntil: 'load', timeout: 60000 });
    await page.waitForTimeout(900);
  }

  async function shot(fileName) {
    await page.screenshot({
      path: path.join(outDir, fileName),
      fullPage: false,
    });
    console.log(`Saved ${fileName}`);
  }

  /**
   * Find vertical anchors of distinct UI blocks (not table rows).
   * Prefer Orchid section titles / white cards that look like layout blocks.
   */
  async function findBlockAnchors() {
    return page.evaluate(() => {
      const root =
        document.querySelector('.workspace-content') ||
        document.querySelector('main') ||
        document.body;

      const anchors = [];
      const seenTops = [];

      const push = (el, label) => {
        if (!el) return;
        const rect = el.getBoundingClientRect();
        const top = Math.round(rect.top + window.scrollY);
        if (rect.height < 24) return;
        if (seenTops.some((t) => Math.abs(t - top) < 120)) return;
        seenTops.push(top);
        anchors.push({
          top,
          label: (label || el.textContent || '').trim().replace(/\s+/g, ' ').slice(0, 60),
        });
      };

      // Orchid Layout::block titles often render as headings near cards
      root.querySelectorAll('h1, h2, h3, h4, legend').forEach((el) => {
        const text = (el.textContent || '').trim();
        if (text.length < 2 || text.length > 80) return;
        // Skip tiny UI chrome
        if (/^поиск$/i.test(text)) return;
        push(el, text);
      });

      // Large white cards (metric rows / blocks)
      root.querySelectorAll('.bg-white.rounded, .bg-white.shadow-sm, .card').forEach((el) => {
        const rect = el.getBoundingClientRect();
        if (rect.height < 160) return;
        const titleEl = el.querySelector('h3, h4, .card-title, small, legend');
        push(el, titleEl ? titleEl.textContent : 'блок');
      });

      anchors.sort((a, b) => a.top - b.top);
      return anchors;
    });
  }

  async function captureSingle(prefix) {
    await page.evaluate(() => window.scrollTo(0, 0));
    await page.waitForTimeout(200);
    await shot(`${prefix}-1.png`);
    return 1;
  }

  async function captureBlocks(prefix) {
    await page.evaluate(() => window.scrollTo(0, 0));
    await page.waitForTimeout(200);
    await shot(`${prefix}-1.png`);

    const viewport = height;
    const anchors = await findBlockAnchors();
    // Blocks that start meaningfully below the first fold
    const below = anchors.filter((a) => a.top > viewport * 0.55);
    console.log(
      `  anchors=${anchors.length}, below_fold=${below.length}:`,
      below.map((b) => `${b.label}@${b.top}`).join(' | ') || '(none)',
    );

    if (below.length === 0) {
      console.log(`  → only one screen needed for ${prefix}`);
      return 1;
    }

    let index = 2;
    let lastScroll = 0;
    const maxShots = 5;

    for (const block of below) {
      if (index > maxShots) break;
      // Place block near top of viewport
      const targetY = Math.max(0, block.top - 70);
      if (Math.abs(targetY - lastScroll) < viewport * 0.35) {
        continue; // too close to previous shot
      }
      await page.evaluate((y) => window.scrollTo(0, y), targetY);
      await page.waitForTimeout(400);
      await shot(`${prefix}-${index}.png`);
      lastScroll = targetY;
      index += 1;
    }

    return index - 1;
  }

  const manifest = [];

  for (const cfg of PAGES) {
    console.log(`\n=== ${cfg.prefix} ${cfg.path} mode=${cfg.mode} ===`);
    await open(cfg.path);
    let count = 1;
    if (cfg.mode === 'single') {
      count = await captureSingle(cfg.prefix);
    } else {
      count = await captureBlocks(cfg.prefix);
    }
    manifest.push({
      prefix: cfg.prefix,
      path: cfg.path,
      mode: cfg.mode,
      title: cfg.title,
      intro: cfg.intro,
      shots: count,
    });
  }

  fs.writeFileSync(path.join(outDir, 'pages.json'), JSON.stringify(manifest, null, 2));
  console.log(`\nWrote pages.json (${manifest.length} pages)`);

  await browser.close();
})().catch((err) => {
  console.error(err);
  process.exit(1);
});
