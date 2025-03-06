 Theme and Template System Overview pre { background-color: #f4f4f4; padding: 8px; overflow-x: auto; } code { font-family: monospace; }

# Theme and Template System Overview

This document provides a comprehensive overview of the new theme/template system used in the CMS. In the new system, all template variables and functions are encapsulated in a central object named **$tpl** (an instance of `TemplateVars`), which streamlines template development and maintenance.

## Changing the Template

*   **Location:** You can change the template in the admin panel under **Settings > Design**.
*   **Flexibility:** Switching between different themes and layouts is now even more streamlined with the unified **$tpl** object.

## Custom Themes

*   **Creation:** A custom theme can be created by placing its folder inside the main theme directory.
*   **Mandatory File:** Each theme must include a `theme.json` file, which provides essential information about the theme (such as its name, version, author, etc.).
*   **Template Integration:** Templates in custom themes access all necessary data and functions via **$tpl**.

## Template Variables Overview

In the new system, template files no longer rely on custom curly-brace tags. Instead, all data is accessed through the **$tpl** object. This centralizes the variables and functions, making your templates cleaner and easier to manage.

### Escaped Variable Output

*   **Usage:** Instead of using a template tag like `{$variableName}`, use:
    
    ```
    <?php echo marques_escape_html($tpl->variableName); ?>
    ```
    
*   **Effect:** Outputs the value of the variable, escaping it using `htmlspecialchars`.

### Unescaped Variable Output

*   **Usage:** Instead of `{!$variableName}`, simply use:
    
    ```
    <?php echo $tpl->variableName; ?>
    ```
    
*   **Effect:** Outputs the value of the variable without escaping.

### Conditional Statements

*   **Usage:** Use standard PHP conditionals with **$tpl**:
    
    ```
    <?php if (!empty($tpl->userIsLoggedIn)): ?>
        <!-- content for logged in users -->
    <?php else: ?>
        <!-- content for guests -->
    <?php endif; ?>
    ```
    

### Loops (foreach)

*   **Usage:** Use PHP’s `foreach` loops to iterate over arrays stored in **$tpl**:
    
    ```
    <?php foreach ($tpl->users as $user): ?>
        <?php echo $user; ?>
    <?php endforeach; ?>
    ```
    
*   **With Key and Value:**
    
    ```
    <?php foreach ($tpl->products as $id => $product): ?>
        <?php echo $product; ?>
    <?php endforeach; ?>
    ```
    

### Function Calls

*   **Usage:** Call functions or methods directly via **$tpl**. For example, to get the URL for theme assets:
    
    ```
    <?php echo $tpl->themeUrl('path/to/asset'); ?>
    ```
    
*   **Effect:** Executes the function and outputs its result.

### Including Partials

*   **Usage:** Instead of a custom include tag, use the dedicated method:
    
    ```
    <?php $this->includePartial('header'); ?>
    ```
    
*   **Additional Data:** If needed, pass extra parameters via **$tpl** before calling the partial.

### Navigation Rendering

*   **Usage:** Navigation elements can be rendered using dedicated functions, either via **$tpl** or the theme manager. For example:
    
    ```
    <?php echo $tpl->mainMenu(); ?>
    ```
    
*   **Note:** Depending on your theme, navigation rendering might be handled by methods integrated into **$tpl** or directly via the theme manager.

## Summary

The template system centralizes all template data and functionality within the **$tpl** object (an instance of `TemplateVars`). This eliminates the need for a separate template tag syntax and leverages standard PHP constructs for conditional statements, loops, and function calls. As a result, your templates become more consistent and maintainable while still offering full flexibility.