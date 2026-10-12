#!/usr/bin/env python3
"""Generate manager brief PDF: entities + mail distribution + screenshots."""

from __future__ import annotations

from pathlib import Path

from reportlab.lib import colors
from reportlab.lib.enums import TA_CENTER, TA_JUSTIFY, TA_LEFT
from reportlab.lib.pagesizes import A4
from reportlab.lib.styles import ParagraphStyle, getSampleStyleSheet
from reportlab.lib.units import cm
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.platypus import (
    Image,
    KeepTogether,
    ListFlowable,
    ListItem,
    PageBreak,
    Paragraph,
    SimpleDocTemplate,
    Spacer,
)

SCRIPT_DIR = Path(__file__).resolve().parent
SCREENSHOTS = SCRIPT_DIR / "screenshots"
OUTPUT = SCRIPT_DIR / "AI-PM-MVP-test-brief.pdf"
FONT_PATH = Path("/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf")
FONT_BOLD_PATH = Path("/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf")

FOOTER_NOTE = "AI PM MVP — обзор для менеджера · черновик · 24.09.2026"


def register_fonts() -> tuple[str, str]:
    if not FONT_PATH.is_file():
        raise SystemExit(
            f"DejaVu font not found at {FONT_PATH}. "
            "Install: sudo apt-get install -y fonts-dejavu-core"
        )
    pdfmetrics.registerFont(TTFont("DejaVu", str(FONT_PATH)))
    bold = "DejaVu"
    if FONT_BOLD_PATH.is_file():
        pdfmetrics.registerFont(TTFont("DejaVu-Bold", str(FONT_BOLD_PATH)))
        bold = "DejaVu-Bold"
    return "DejaVu", bold


def build_styles(base: str, bold: str):
    styles = getSampleStyleSheet()
    styles.add(
        ParagraphStyle(
            name="TitleRu",
            parent=styles["Title"],
            fontName=bold,
            fontSize=20,
            leading=26,
            alignment=TA_CENTER,
            spaceAfter=8,
        )
    )
    styles.add(
        ParagraphStyle(
            name="SubtitleRu",
            parent=styles["Normal"],
            fontName=base,
            fontSize=11,
            leading=15,
            alignment=TA_CENTER,
            textColor=colors.HexColor("#444444"),
            spaceAfter=18,
        )
    )
    styles.add(
        ParagraphStyle(
            name="HeadingRu",
            parent=styles["Heading2"],
            fontName=bold,
            fontSize=13,
            leading=17,
            spaceBefore=12,
            spaceAfter=6,
            textColor=colors.HexColor("#1a1a1a"),
        )
    )
    styles.add(
        ParagraphStyle(
            name="SubHeadRu",
            parent=styles["Normal"],
            fontName=bold,
            fontSize=11,
            leading=14,
            spaceBefore=8,
            spaceAfter=4,
        )
    )
    styles.add(
        ParagraphStyle(
            name="BodyRu",
            parent=styles["Normal"],
            fontName=base,
            fontSize=10,
            leading=14,
            alignment=TA_JUSTIFY,
            spaceAfter=7,
        )
    )
    styles.add(
        ParagraphStyle(
            name="BulletRu",
            parent=styles["Normal"],
            fontName=base,
            fontSize=10,
            leading=13,
            alignment=TA_LEFT,
            leftIndent=0,
        )
    )
    styles.add(
        ParagraphStyle(
            name="CaptionRu",
            parent=styles["Normal"],
            fontName=base,
            fontSize=9,
            leading=12,
            alignment=TA_LEFT,
            textColor=colors.HexColor("#333333"),
            spaceBefore=6,
            spaceAfter=4,
        )
    )
    styles.add(
        ParagraphStyle(
            name="MonoRu",
            parent=styles["Normal"],
            fontName=base,
            fontSize=9,
            leading=12,
            alignment=TA_LEFT,
            textColor=colors.HexColor("#222222"),
            backColor=colors.HexColor("#f3f4f6"),
            borderPadding=6,
            spaceBefore=4,
            spaceAfter=8,
        )
    )
    return styles


def add_footer(canvas, doc):
    canvas.saveState()
    canvas.setFont("DejaVu", 8)
    canvas.setFillColor(colors.HexColor("#888888"))
    canvas.drawCentredString(A4[0] / 2, 1.1 * cm, FOOTER_NOTE)
    canvas.drawRightString(A4[0] - 1.5 * cm, 1.1 * cm, str(doc.page))
    canvas.restoreState()


def scaled_image(path: Path, max_width: float, max_height: float) -> Image:
    img = Image(str(path))
    iw, ih = img.imageWidth, img.imageHeight
    scale = min(max_width / iw, max_height / ih, 1.0)
    img.drawWidth = iw * scale
    img.drawHeight = ih * scale
    return img


def bullets(items: list[str], styles) -> ListFlowable:
    return ListFlowable(
        [
            ListItem(Paragraph(t, styles["BulletRu"]), leftIndent=8, bulletColor=colors.HexColor("#333333"))
            for t in items
        ],
        bulletType="bullet",
        start="•",
        leftIndent=14,
        spaceBefore=2,
        spaceAfter=8,
    )


def main() -> None:
    base, bold = register_fonts()
    styles = build_styles(base, bold)

    doc = SimpleDocTemplate(
        str(OUTPUT),
        pagesize=A4,
        leftMargin=1.8 * cm,
        rightMargin=1.8 * cm,
        topMargin=1.6 * cm,
        bottomMargin=2.0 * cm,
    )

    story: list = []

    # --- Title ---
    story.append(Spacer(1, 1.2 * cm))
    story.append(Paragraph("AI-помощник менеджера проектов", styles["TitleRu"]))
    story.append(
        Paragraph(
            "Краткий обзор MVP: сущности, как распределяются письма, интерфейс<br/>24.09.2026",
            styles["SubtitleRu"],
        )
    )

    # --- What ---
    story.append(Paragraph("1. Зачем система", styles["HeadingRu"]))
    story.append(
        Paragraph(
            "Менеджер ведёт много проектов. В почту приходит поток писем: клиенты, "
            "Redmine/Jira, заявки с сайтов, рассылки, системный шум. Цель MVP — "
            "автоматически превратить этот поток в понятную картину: к какому проекту "
            "письмо, что произошло, нужно ли вмешательство менеджера и (если нужно) "
            "черновик ответа.",
            styles["BodyRu"],
        )
    )
    story.append(
        Paragraph(
            "Важно: ИИ <b>не отправляет</b> письма сам и <b>не пишет в базу напрямую</b>. "
            "Агент Hermes только предлагает решение; Laravel проверяет и применяет. "
            "Менеджер всегда может поправить классификацию, проект или отклонить предложение.",
            styles["BodyRu"],
        )
    )

    # --- Entities ---
    story.append(Paragraph("2. Основные сущности (простым языком)", styles["HeadingRu"]))
    story.append(
        Paragraph(
            "Ниже — «словарик» системы. Названия в интерфейсе на русском; "
            "в скобках — как это связано между собой.",
            styles["BodyRu"],
        )
    )

    entities = [
        (
            "Проект",
            "Папка работы менеджера (например RBI, STAVNI, APEX). У проекта есть "
            "название, статус, инструкция для ИИ и правила: какие адреса, домены, "
            "темы или ключевые слова относить к нему. Письма, задачи и события "
            "привязываются к проекту.",
        ),
        (
            "Письмо (входящее сообщение)",
            "Одно нормализованное письмо из почты (не «сырой» IMAP). Хранит отправителя, "
            "тему, текст, дату, классификацию, проект, краткое описание от ИИ, статус "
            "обработки. Все дальнейшие шаги работают с этим письмом, а не с провайдером.",
        ),
        (
            "Переписка (диалог)",
            "Цепочка связанных писем (ответы в одной ветке). Нужна, чтобы ИИ понимал "
            "контекст: «о чём сейчас речь», а не только последнее письмо. Если одно "
            "письмо в цепочке получило проект, система может протянуть тот же проект "
            "на соседние письма без проекта.",
        ),
        (
            "Задача",
            "Единица работы внутри проекта: тикет Redmine (#123), ключ Jira (GIG-72), "
            "уведомление Aspro или «диалог» по клиентской переписке. На задаче "
            "хранятся менеджер и исполнители (не на всём проекте целиком). Много "
            "писем могут относиться к одной задаче.",
        ),
        (
            "Событие",
            "Что система «поняла» по письму: вопрос клиента, одобрение, обновление "
            "задачи, заявка с сайта и т.п. Это не само письмо, а смысл произошедшего. "
            "По событию обновляется картина проекта и при необходимости создаётся "
            "действие для менеджера.",
        ),
        (
            "Действие менеджера",
            "Предложение системы: посмотреть, ответить, напомнить. Может содержать "
            "черновик ответа. Менеджер подтверждает, правит или отклоняет. В одной "
            "переписке обычно одно «ожидающее» действие — по самому свежему письму.",
        ),
        (
            "Клиент (компания) и контакт",
            "Справочник: компания-заказчик и люди в ней (ФИО, почта, телефон). "
            "Связываются с проектами, чтобы понимать, кто пишет и к кому проекту "
            "относится адрес.",
        ),
        (
            "Исполнитель",
            "Сотрудник нашей команды. Назначается на задачу как менеджер задачи "
            "(часто «Автор» из Redmine) или как исполнитель («Назначена»). "
            "На карточке проекта люди показываются собранными с задач.",
        ),
        (
            "Классификация письма",
            "Тип письма: клиентское, заявка с сайта, техническое (Redmine/Jira), "
            "рассылка, личное, игнорируемое, неизвестное. От типа зависит, нужен ли "
            "полный анализ ИИ и как искать проект.",
        ),
        (
            "Фильтры почты (чёрный список)",
            "Глобальные правила «не трогать»: адреса, домены, стоп-слова в теме/тексте. "
            "Такие письма попадают в «Игнорируемые» и не считаются работой по проекту.",
        ),
    ]

    for title, text in entities:
        story.append(Paragraph(title, styles["SubHeadRu"]))
        story.append(Paragraph(text, styles["BodyRu"]))

    story.append(Paragraph("Как сущности связаны", styles["SubHeadRu"]))
    story.append(
        Paragraph(
            "Проект содержит задачи и письма. Письмо принадлежит переписке и может "
            "указывать на задачу. По письму создаётся событие; из события — действие "
            "менеджера. Клиенты и исполнители связаны с проектами и (исполнители) "
            "с задачами. Схема «сверху вниз»:",
            styles["BodyRu"],
        )
    )
    story.append(
        Paragraph(
            "Проект → Задачи + Письма + События<br/>"
            "Переписка → несколько Писем<br/>"
            "Письмо → Событие → (при необходимости) Действие менеджера<br/>"
            "Задача → менеджер и исполнители",
            styles["MonoRu"],
        )
    )

    # --- Distribution ---
    story.append(PageBreak())
    story.append(Paragraph("3. Как происходит распределение писем", styles["HeadingRu"]))
    story.append(
        Paragraph(
            "«Распределение» здесь — это весь путь от появления письма в ящике "
            "до привязки к проекту, задачи и понятного результата для менеджера. "
            "Шаги идут по очереди; длинный ИИ-анализ выполняется в фоне (очередь), "
            "а не в момент открытия страницы.",
            styles["BodyRu"],
        )
    )

    story.append(Paragraph("Шаг 0. Синхронизация почты", styles["SubHeadRu"]))
    story.append(
        Paragraph(
            "По расписанию (или кнопкой «Синхронизировать почту») система забирает "
            "новые письма из Яндекс.Почты по IMAP. Каждое письмо сохраняется один раз "
            "(повторный запуск не создаёт дублей). Из «сырого» письма получается "
            "нормализованное входящее сообщение.",
            styles["BodyRu"],
        )
    )

    story.append(Paragraph("Шаг 1. Классификация — что это за письмо", styles["SubHeadRu"]))
    story.append(
        Paragraph(
            "Сначала срабатывают жёсткие правила (без ИИ), по приоритету:",
            styles["BodyRu"],
        )
    )
    story.append(
        bullets(
            [
                "<b>Заявка с сайта</b> — формы вроде «Заявка на бронирование / обратный звонок» "
                "с известных доменов (например rbi.ru). Даже если адрес noreply@ в чёрном списке, "
                "такие письма не игнорируются.",
                "<b>Игнорируемое</b> — глобальный чёрный список (системный шум, Яндекс ID и т.п.).",
                "<b>Техническое</b> — Redmine, Jira, Aspro и похожие уведомления по проектам.",
                "<b>Рассылка / личное</b> — по заголовкам и спискам адресов.",
                "<b>Клиентское</b> — совпадение с правилами проекта (email, домен, тема, ключевые слова).",
                "<b>Неизвестное</b> — если ни одно правило не сработало: письмо ждёт ручного разбора "
                "или подсказки ИИ.",
            ],
            styles,
        )
    )

    story.append(Paragraph("Шаг 2. Поиск проекта — к чему отнести", styles["SubHeadRu"]))
    story.append(
        Paragraph(
            "Проект ищется <b>не сразу через ИИ по всем проектам</b>, а ступенями:",
            styles["BodyRu"],
        )
    )
    story.append(
        bullets(
            [
                "<b>Правила проекта</b> (детерминированный матчер): точный email, домен, "
                "тема, ключевые слова, технический источник, уже известная переписка. "
                "Если совпадение уверенное — проект ставится сразу.",
                "<b>Несколько кандидатов</b> — письмо не угадывают: оставляют без проекта "
                "или показывают менеджеру на экране «Распределение».",
                "<b>Автосоздание проекта из Redmine</b> (если включено в настройках): "
                "из темы вида «[Название проекта - Bug #…]» можно создать или привязать "
                "проект без ИИ.",
                "<b>Автораспределение через LLM</b> (если включено): для писем всё ещё "
                "без проекта ИИ предлагает существующий проект или имя нового — "
                "но только если уверенность не ниже порога из настроек. Ниже порога — "
                "в «Требуют ручной обработки», без выдуманного проекта.",
                "<b>Протягивание по переписке</b>: если одно письмо в диалоге получило "
                "проект, соседние письма без проекта могут получить тот же.",
            ],
            styles,
        )
    )

    story.append(Paragraph("Шаг 3. Задача и люди", styles["SubHeadRu"]))
    story.append(
        Paragraph(
            "После проекта система находит или создаёт задачу (тикет / диалог). "
            "Из тела Redmine («Автор», «Назначена») или (при пробелах) с помощью ИИ "
            "на задачу назначаются менеджер и исполнители. Уже заданный менеджер задачи "
            "не перезаписывается.",
            styles["BodyRu"],
        )
    )

    story.append(Paragraph("Шаг 4. Анализ смысла (Hermes + LLM)", styles["SubHeadRu"]))
    story.append(
        Paragraph(
            "ИИ вызывается не для каждого письма. Обычно нужен анализ, когда письмо "
            "клиентское / заявка с сайта / неизвестное (и есть проект или кандидаты) "
            "или техническое с уже известным проектом (короткое резюме без черновика).",
            styles["BodyRu"],
        )
    )
    story.append(
        Paragraph(
            "Hermes получает урезанный контекст: текст последнего ответа, сжатую память "
            "диалога, краткие сводки прошлых писем, инструкцию проекта, кандидатов — "
            "не весь ящик. На выходе строгое предложение: тип события, краткое описание, "
            "нужен ли менеджер, черновик ответа (если уместно). Laravel проверяет JSON "
            "и допустимые значения; при ошибке анализ помечается неуспешным. "
            "При временных лимитах Gemini письмо не «ломается» сразу — анализ "
            "откладывается и повторяется.",
            styles["BodyRu"],
        )
    )

    story.append(Paragraph("Шаг 5. Результат для менеджера", styles["SubHeadRu"]))
    story.append(
        bullets(
            [
                "Создаётся <b>событие</b> («что произошло»).",
                "При необходимости — <b>действие менеджера</b> с черновиком.",
                "Менеджер смотрит письмо в «Входящих», правит проект/классификацию, "
                "подтверждает или отклоняет действие.",
                "ИИ по-прежнему <b>не отправляет</b> ответ клиенту сам.",
            ],
            styles,
        )
    )

    story.append(Paragraph("Где менеджер участвует руками", styles["SubHeadRu"]))
    story.append(
        bullets(
            [
                "«Входящие» — фильтры и плашки (ожидают, без проекта, заявки с сайта…).",
                "«Распределение» / «Распределение (LLM)» — массово привязать письма к проектам.",
                "«Действия менеджера» — очередь предложений ИИ.",
                "Карточка письма — ручная правка классификации и проекта.",
                "«Настройки» — включить автосоздание проектов Redmine и LLM-распределение, пороги уверенности.",
            ],
            styles,
        )
    )

    # --- Screenshots from capture manifest ---
    import json
    import re

    manifest_path = SCREENSHOTS / "pages.json"
    if not manifest_path.is_file():
        raise SystemExit(f"Missing {manifest_path} — run bash docs/manager-brief/run_capture.sh")

    manifest = json.loads(manifest_path.read_text(encoding="utf-8"))

    # Пояснения для менеджера: что видно на экране (без служебных фраз про «сколько скринов»).
    PAGE_GUIDES: dict[str, str] = {
        "01-dashboard": (
            "Главный экран после входа. <b>Плашки сверху</b> — живые счётчики: сколько писем всего, "
            "сколько ждут обработки, требуют ручной разборки, неизвестных, игнорируемых, "
            "требуют внимания менеджера, сколько проектов. Нажатие на плашку открывает "
            "отфильтрованный список. <b>Ожидающие действия менеджера</b> — очередь предложений ИИ "
            "(проверить письмо, ответить клиенту). <b>Лента событий</b> — что система недавно "
            "«поняла» по письмам. Внизу — письма без проекта / на ручной разбор."
        ),
        "02-inbox": (
            "Рабочий список всей почты. <b>Плашки</b> — быстрые фильтры (клиентские, заявки с сайта, "
            "технические, без проекта и т.д.). <b>Фильтры</b> ниже — точный отбор по классификации, "
            "статусу обработки, проекту, признаку «нужен менеджер». "
            "В строке письма: <b>тема</b>, дата, и главное — <b>краткое описание</b>: это не цитата "
            "из письма, а сжатый итог, который составил ИИ (Hermes) после анализа. Под описанием — "
            "<b>теги</b>: классификация (клиентское / техническое / заявка с сайта…), проект, задача, "
            "тип события, нужен ли менеджер, статус обработки, уверенность ИИ (ориентир, не «истина»)."
        ),
        "03-projects": (
            "Список проектов. Каждый проект — «папка» работы: к нему привязываются письма, задачи "
            "и события. В колонке менеджеров видны люди, собранные с задач проекта (не выбираются "
            "на всём проекте целиком)."
        ),
        "04-tasks": (
            "Список задач. Задача — единица работы: тикет Redmine/Jira, уведомление Aspro или "
            "диалог по переписке. У задачи свой <b>менеджер</b> и исполнители. Много писем могут "
            "относиться к одной задаче."
        ),
        "05-manager-actions": (
            "Очередь предложений для менеджера. Система не пишет клиенту сама: здесь лежит "
            "черновик / рекомендация (ответить, проверить, напомнить). Менеджер открывает карточку "
            "и подтверждает, правит или отклоняет."
        ),
        "06-events": (
            "Журнал <b>событий</b> — не сами письма, а смысл: «клиент спросил», «задача обновлена», "
            "«заявка с сайта» и т.п. Событие создаётся после проверки результата ИИ в Laravel."
        ),
        "07-assign": (
            "Экран ручного распределения: письма без проекта группируются по кандидатам "
            "из правил сопоставления (без ИИ). Можно массово привязать к проекту или создать проект."
        ),
        "08-assign-llm": (
            "То же распределение, но с подсказками большой языковой модели: к какому существующему "
            "проекту отнести письмо (с учётом порога уверенности из настроек)."
        ),
        "09-settings": (
            "Глобальные переключатели автоматики: создавать проекты из Redmine-писем и "
            "автоматически распределять неразмеченные письма через LLM. Рядом — пороги уверенности: "
            "ниже порога система не «угадывает» проект."
        ),
        "10-mail-filters": (
            "Глобальный чёрный список: адреса, домены, стоп-слова в теме и тексте. "
            "Совпадения попадают в «Игнорируемые» и не считаются работой по проекту "
            "(кроме особых случаев вроде заявок с сайта)."
        ),
        "11-clients": (
            "Справочник компаний-клиентов. Нужен, чтобы понимать, кто пишет и с какими "
            "проектами связан контакт."
        ),
        "12-executors": (
            "Справочник сотрудников нашей команды. Назначаются на задачи как менеджер задачи "
            "или исполнитель (часто подтягиваются из полей Redmine «Автор» / «Назначена»)."
        ),
        "13-metrics": (
            "Метрики гипотезы MVP: сколько писем получили, сколько клиентских/технических, "
            "сколько проектов определилось автоматически, сколько черновиков приняли/правили. "
            "Нужны, чтобы оценить пользу помощника на реальной почте."
        ),
        "14-admin-tools": (
            "Сервисные команды: синхронизация почты, переобработка писем, зачистка устаревших "
            "действий, отчёты. Запускаются кнопками; результат пишется в журнал."
        ),
        "15-ignored": (
            "Письма, отсечённые чёрным списком (системный шум, рассылки ID и т.п.). "
            "Их можно пересобрать после изменения фильтров."
        ),
        "16-message-view": (
            "Карточка одного письма. Здесь видно <b>исходные данные</b> (от кого, тема, текст) и "
            "<b>результат анализа</b>: классификация, проект, задача, <b>краткое описание</b> "
            "(сформировал ИИ — одно-два предложения «что произошло сейчас»), уверенность, "
            "нужен ли менеджер. Ниже — журнал шагов обработки (классификация, матчинг проекта, "
            "вызов Hermes, ошибки/повторы) и правки менеджера, если он менял решение системы."
        ),
        "17-task-view": (
            "Карточка задачи: ключ/название, проект, статус. <b>Менеджер задачи</b> и "
            "<b>исполнители</b> можно менять вручную. Список связанных писем — все уведомления "
            "и переписка по этой единице работы."
        ),
        "18-project-edit": (
            "Карточка проекта. Вверху — название, описание, статус и <b>инструкция для ИИ</b> "
            "(свободный текст особенностей проекта) плюс <b>правила сопоставления</b> "
            "(emails, домены, темы, ключевые слова — по ним письма цепляются без LLM). "
            "Блоки менеджеров/исполнителей — обзор людей с задач. Дальше — задачи, письма и события проекта."
        ),
        "19-manager-action-view": (
            "Карточка предложения менеджеру: зачем система просит вмешаться, тип действия, "
            "<b>черновик ответа</b> (если нужен reply) — текст тоже предлагает ИИ, но отправка "
            "только после вашего решения. Кнопки: подтвердить / отредактировать / отклонить."
        ),
        "20-event-view": (
            "Карточка события: тип (вопрос клиента, обновление задачи, заявка с сайта…), "
            "краткое описание, связь с письмом и проектом, уверенность, применено ли событие."
        ),
        "21-executor-edit": (
            "Карточка исполнителя: ФИО, контакты, активность, проекты. Используется при "
            "назначении людей на задачи."
        ),
    }

    def collect_parts(prefix: str) -> list[Path]:
        numbered = sorted(
            [
                p
                for p in SCREENSHOTS.glob(f"{prefix}-*.png")
                if p.stem.split("-")[-1].isdigit()
            ],
            key=lambda p: int(p.stem.split("-")[-1]),
        )
        return numbered

    def clean_meta_intro(text: str) -> str:
        text = re.sub(
            r"\s*Ниже первого экрана[^.]*\.",
            "",
            text,
            flags=re.IGNORECASE,
        )
        text = re.sub(
            r"\s*Ниже — только продолжение[^.]*\.",
            "",
            text,
            flags=re.IGNORECASE,
        )
        text = re.sub(
            r"\s*Один скрин[^.]*\.",
            "",
            text,
            flags=re.IGNORECASE,
        )
        text = re.sub(
            r"\s*Несколько скринов[^.]*\.",
            "",
            text,
            flags=re.IGNORECASE,
        )
        text = re.sub(
            r"\s*поэтому несколько скринов\.?",
            "",
            text,
            flags=re.IGNORECASE,
        )
        return text.strip()

    fig_n = 0
    for entry in manifest:
        fig_n += 1
        prefix = entry["prefix"]
        title = f"{3 + fig_n}. Интерфейс: {entry['title']}"
        intro = PAGE_GUIDES.get(prefix) or clean_meta_intro(entry.get("intro") or "")

        paths = collect_parts(prefix)
        if not paths:
            raise SystemExit(f"No screenshots for {prefix}")

        story.append(PageBreak())
        story.append(Paragraph(title, styles["HeadingRu"]))
        story.append(Paragraph(intro, styles["BodyRu"]))
        total = len(paths)
        for i, path in enumerate(paths, start=1):
            if i > 1:
                story.append(PageBreak())
                story.append(
                    Paragraph(
                        f"{entry['title']} (продолжение)",
                        styles["HeadingRu"],
                    )
                )
            story.append(scaled_image(path, doc.width, doc.height * 0.68))
            if i == 1:
                caption = f"Рис. {fig_n}. {entry['title']}."
            else:
                caption = f"Рис. {fig_n} (продолжение). {entry['title']}."
            story.append(Paragraph(caption, styles["CaptionRu"]))

    doc.build(story, onFirstPage=add_footer, onLaterPages=add_footer)
    size = OUTPUT.stat().st_size
    print(f"Created: {OUTPUT}")
    print(f"Size: {size} bytes ({size / 1024:.1f} KiB)")


if __name__ == "__main__":
    main()
