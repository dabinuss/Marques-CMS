# Marques CMS

<img src="assets/logo-text-bg.jpg" alt="marques logo" width="400"/>

Welcome to **Marques CMS** – a modular, flexible, and file-based (Flat File) content management system developed in multiple stages. 🎉

## Overview

Marques CMS is designed to lay the foundation for a modern, user-friendly CMS—without using a traditional database! With a clean architecture and modular structure, we aim to make content creation and management as pleasant as possible. Development is carried out in clearly defined phases, each adding important core functionality and extended features.

## Requirements

Marques requires no databases, packages, or server environments. It works on simple web hosting services. Therefore, there are no specific requirements.
- **No requirements** other than web space

## Installation

The CMS is still under development. For testing, simply upload the project under `/marques/` and open it in your browser. In `/config/users.config.php/`, you can create and edit users. Note that passwords must be stored as PHP password hashes.

## Development Phases

<details>
<summary>### Phase 1: Basic Structure and Core | ✅ Done </summary>

In this phase, the base architecture and fundamental features were implemented:

- **Project Structure:** Setup of folders and files
- **Core Modules:** 
  - **Router:** For handling URLs
  - **Content Parser:** For processing Markdown content
  - **Template Engine:** For rendering content
- **Configuration Files:** Creation and management of system settings
- **Templates and Partials:** Development of reusable template components
- **Assets:** Setup of CSS/JS resources
- **Sample Content:** Initial static content for demonstration
- **Admin Area:** Basic structure for administrative tasks

</details>

<details>
<summary>### Phase 2: Planning and Components | ✅ Done </summary>


In this phase, extended functionality was added to improve interactivity and security:

- **Secure Authentication:** Login system with password hashing, session management & access controls
- **Admin Dashboard:** Clear start page, navigation to all administration areas
- **Content Management:** Create, edit, and delete pages, manage blog posts, version management for content
- **TinyMCE Integration:** TinyMCE WYSIWYG editor for easy content creation, Markdown support
- **Media Management:** Media upload, media library, integration into the editor

</details>

### Phase 3: Extension

In the third phase, we plan to refine the CMS further and add additional features:

- **Extended Features:** Tags, categories, etc.
- **Caching System:** Performance improvements
- **SEO Functions:** Search engine optimization
- **User Roles and Permissions:** Granular access control
- **Navigation Management:** Manage links in the navigation

### Phase 4: Optimization

- **Bug Fixes:** Major error detection and resolution
- **Performance Optimization:** More efficient system behavior

## Feature Overview

| Development Phase | Feature                                                                  | Status          |
|-------------------|--------------------------------------------------------------------------|-----------------|
| **Phase 3**       | Navigation Management                                                    | ✅ Done         |
| **Phase 3**       | Reworked Config Manager                                                  | ✅ Done         |
| **Phase 3**       | CMS System Settings                                                      | 🔄 In Progress  |
| **Phase 3**       | CMS System Settings: Configurable Blog URLs                              | ✅ Done         |
| **Phase 3**       | Theme Manager / Custom Themes                                            | ✅ Done         |
| **Phase 3**       | Theme Manager / Custom Themes: REWORK                                            | ✅ Done         |
| **Phase 3**       | Caching System                                                           | ❌ Not Yet      |
| **Phase 3**       | SEO Functions                                                            | ❌ Not Yet      |
| **Phase 3**       | User Roles and Permissions                                               | 🔄 In Progress  |
| **Phase 4**       | Major Bug Hunting and Fixing                                             | ❌ Not Yet      |
| **Phase 4**       | Performance Optimization                                                 | ❌ Not Yet      |
| **Release V. 1.0** | Potential Installation Script                                           | ❌ Not Yet      |

<details><summary>Completed Development Phases</summary>

| Development Phase | Feature                                                                  | Status          |
|-------------------|--------------------------------------------------------------------------|-----------------|
| **Phase 1**       | Project Structure: Setup of folders and files                            | ✅ Done         |
| **Phase 1**       | Core Module: Router (URL Handling)                                       | ✅ Done         |
| **Phase 1**       | Core Module: Content Parser (Markdown Processing)                        | ✅ Done         |
| **Phase 1**       | Core Module: Template Engine (Rendering)                                 | ✅ Done         |
| **Phase 1**       | Configuration Files                                                      | ✅ Done         |
| **Phase 1**       | Templates and Partials                                                   | ✅ Done         |
| **Phase 1**       | CSS/JS Assets                                                            | ✅ Done         |
| **Phase 1**       | Sample Content                                                           | ✅ Done         |
| **Phase 1**       | Basic Admin Area Structure                                               | ✅ Done         |
| **Phase 2**       | Secure Authentication: Login system with password hashing                | ✅ Done         |
| **Phase 2**       | Secure Authentication: Session Management                                | ✅ Done         |
| **Phase 2**       | Secure Authentication: Access Controls                                   | ✅ Done         |
| **Phase 2**       | Admin Dashboard: Clear start page                                        | ✅ Done         |
| **Phase 2**       | Admin Dashboard: Navigation to all administration areas                  | ✅ Done         |
| **Phase 2**       | Content Management: Create, edit, delete pages                           | ✅ Done         |
| **Phase 2**       | Content Management: Manage blog posts                                    | ✅ Done         |
| **Phase 2**       | Content Management: Version management for content                       | ✅ Done         |
| **Phase 2**       | TinyMCE Integration: WYSIWYG editor (TINYMCE)                            | ✅ Done         |
| **Phase 2**       | TinyMCE Integration: Markdown support                                    | ✅ Done         |
| **Phase 2**       | Media Management: Media upload                                           | ✅ Done         |
| **Phase 2**       | Media Management: Media library                                          | ✅ Done         |
| **Phase 2**       | Media Management: Integration into the editor                            | ✅ Done         |
| **Phase 2**       | Extended Features (e.g., Tags, Categories)                               | ✅ Done         |

</details>

## Contribute and Feedback

We welcome contributions, suggestions, and constructive feedback! If you have ideas on how to make Marques CMS even better, or if you just want to chat about the technology, don’t hesitate to get involved. 😊

## License

This project is Open Source. For details, see the [LICENSE](LICENSE) file.

---

Have fun exploring and co-developing Marques CMS! What do you find most exciting about a modular, file-based CMS? 🤔💬
