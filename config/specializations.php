<?php

/**
 * Starter stack lists, keyed by the specialization a workspace picks when it
 * is created.
 *
 * WHY THESE EXIST. `core_stacks` is the one setting nobody can pick a default
 * for — it defines what work is in scope, and getting it wrong means
 * rejecting every job in the workspace's own trade. So the shipped default is
 * empty, WorkspaceReadiness holds scoring back until it is filled in, and a
 * brand-new workspace opens on a setup banner instead of a working board.
 * That is correct but unkind: the owner has to guess what the engine wants
 * before seeing it do anything.
 *
 * A specialization is a much better prior than an empty list. Picking
 * "Graphic design" says more than enough to start scoring, and everything
 * here is ordinary editable settings afterwards — Settings → Bidding rules
 * owns these lists the moment the workspace exists. This is a starting
 * point, never a constraint.
 *
 * DELIBERATELY CONFIG, NOT CODE OR DATABASE. Adding a trade is a pull
 * request, which is the right amount of friction: these lists decide what a
 * paying customer's engine looks at on day one, and they should be reviewed
 * rather than typed into a form at midnight.
 *
 * `excluded_stacks` is left conservative everywhere. An over-eager exclusion
 * silently refuses real work — the sort of thing an owner only discovers
 * weeks later by wondering why the good leads stopped.
 */
return [

    'presets' => [

        'php-laravel' => [
            'label' => 'PHP / Laravel',
            'core_stacks' => ['php', 'laravel', 'mysql', 'livewire', 'vue'],
            'secondary_stacks' => ['javascript', 'tailwind', 'rest api', 'wordpress', 'codeigniter', 'redis'],
            'excluded_stacks' => [],
        ],

        'javascript-fullstack' => [
            'label' => 'JavaScript full-stack (MERN / Next)',
            'core_stacks' => ['javascript', 'typescript', 'react', 'node.js', 'next.js', 'mongodb'],
            'secondary_stacks' => ['express', 'postgresql', 'tailwind', 'graphql', 'vue', 'rest api'],
            'excluded_stacks' => [],
        ],

        'python-ai' => [
            'label' => 'Python / AI & ML',
            'core_stacks' => ['python', 'machine learning', 'openai', 'langchain', 'pytorch', 'llm'],
            'secondary_stacks' => ['django', 'fastapi', 'pandas', 'tensorflow', 'data science', 'rag'],
            'excluded_stacks' => [],
        ],

        'mobile' => [
            'label' => 'Mobile apps',
            'core_stacks' => ['flutter', 'react native', 'swift', 'kotlin', 'ios', 'android'],
            'secondary_stacks' => ['dart', 'firebase', 'app store', 'expo', 'mobile app'],
            'excluded_stacks' => [],
        ],

        'wordpress' => [
            'label' => 'WordPress / WooCommerce',
            'core_stacks' => ['wordpress', 'woocommerce', 'elementor', 'php', 'shopify'],
            'secondary_stacks' => ['css', 'html', 'plugin', 'theme', 'seo', 'divi'],
            'excluded_stacks' => [],
        ],

        'graphic-design' => [
            'label' => 'Graphic design & branding',
            'core_stacks' => ['graphic design', 'logo', 'branding', 'illustrator', 'photoshop', 'figma'],
            'secondary_stacks' => ['packaging', 'brand identity', 'typography', 'canva', 'indesign', 'social media graphics'],
            'excluded_stacks' => [],
        ],

        'ui-ux' => [
            'label' => 'UI / UX design',
            'core_stacks' => ['ui design', 'ux design', 'figma', 'wireframe', 'prototype', 'web design'],
            'secondary_stacks' => ['adobe xd', 'user research', 'design system', 'mobile app design', 'landing page'],
            'excluded_stacks' => [],
        ],

        'game-dev' => [
            'label' => 'Game development',
            'core_stacks' => ['unity', 'unreal engine', 'game development', 'c#', 'godot', 'blender'],
            'secondary_stacks' => ['3d modeling', 'game design', 'multiplayer', 'shader', 'c++', 'animation'],
            'excluded_stacks' => [],
        ],

        'devops-cloud' => [
            'label' => 'DevOps & cloud',
            'core_stacks' => ['devops', 'aws', 'docker', 'kubernetes', 'ci/cd', 'terraform'],
            'secondary_stacks' => ['linux', 'azure', 'google cloud', 'nginx', 'jenkins', 'monitoring'],
            'excluded_stacks' => [],
        ],

        'data-analytics' => [
            'label' => 'Data & analytics',
            'core_stacks' => ['data analysis', 'sql', 'power bi', 'tableau', 'python', 'etl'],
            'secondary_stacks' => ['excel', 'looker', 'bigquery', 'data visualization', 'dashboard', 'snowflake'],
            'excluded_stacks' => [],
        ],

        'video-motion' => [
            'label' => 'Video & motion graphics',
            'core_stacks' => ['video editing', 'after effects', 'premiere pro', 'motion graphics', 'animation'],
            'secondary_stacks' => ['davinci resolve', 'color grading', 'explainer video', '3d animation', 'sound design'],
            'excluded_stacks' => [],
        ],

        'writing-content' => [
            'label' => 'Writing & content',
            'core_stacks' => ['copywriting', 'content writing', 'blog', 'seo writing', 'technical writing'],
            'secondary_stacks' => ['editing', 'proofreading', 'ghostwriting', 'newsletter', 'scriptwriting'],
            'excluded_stacks' => [],
        ],

    ],

];
